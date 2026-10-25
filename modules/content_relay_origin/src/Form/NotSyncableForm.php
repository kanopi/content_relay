<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay_origin\ItemSyncControl;
use Drupal\content_relay_origin\ManualPush;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Marks one item not syncable, or syncable again.
 */
final class NotSyncableForm extends FormBase {

  public function __construct(
    protected ItemSyncControl $control,
    protected NotSyncableFlags $flags,
    protected ManualPush $push,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.item_sync_control'),
      $container->get('content_relay_origin.not_syncable'),
      $container->get('content_relay_origin.manual_push'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_not_syncable';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL): array {
    $entity = $entity_type_id === NULL ? NULL : $this->getRouteMatch()->getParameter($entity_type_id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new NotFoundHttpException();
    }
    $form_state->set('entity', $entity);
    $form += self::elements($entity, $this->flags, $this->push, $this->control);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * The not syncable elements, shared with the entity form.
   *
   * @return array
   *   Form elements: not_syncable, scope, replicas and behavior.
   */
  public static function elements(ContentEntityInterface $entity, NotSyncableFlags $flags, ManualPush $push, ItemSyncControl $control): array {
    $current = $entity->isNew() ? [] : $flags->get($entity->getEntityTypeId(), (string) $entity->uuid());
    $replicas = [];
    foreach ($push->targets($entity) as $replica_id => $replica) {
      $replicas[$replica_id] = $replica->label;
    }
    $all = isset($current[NotSyncableFlags::ALL]);
    $elements['not_syncable'] = [
      '#type' => 'checkbox',
      '#title' => new TranslatableMarkup('Not syncable'),
      '#description' => new TranslatableMarkup('Stop sending this item to replicas.'),
      '#default_value' => $current !== [],
    ];
    $visible = ['visible' => [':input[name="not_syncable"]' => ['checked' => TRUE]]];
    $elements['scope'] = [
      '#type' => 'radios',
      '#title' => new TranslatableMarkup('For'),
      '#options' => [
        'all' => new TranslatableMarkup('All replicas, including ones paired later'),
        'selected' => new TranslatableMarkup('Selected replicas'),
      ],
      '#default_value' => $current === [] || $all ? 'all' : 'selected',
      '#states' => $visible,
      '#access' => $replicas !== [],
    ];
    $elements['replicas'] = [
      '#type' => 'checkboxes',
      '#title' => new TranslatableMarkup('Replicas'),
      '#options' => $replicas,
      '#default_value' => array_values(array_diff(array_keys($current), [NotSyncableFlags::ALL])),
      '#states' => [
        'visible' => [
          ':input[name="not_syncable"]' => ['checked' => TRUE],
          ':input[name="scope"]' => ['value' => 'selected'],
        ],
      ],
      '#access' => $replicas !== [],
    ];
    $elements['behavior'] = [
      '#type' => 'radios',
      '#title' => new TranslatableMarkup('Replicas that already have it'),
      '#options' => [
        NotSyncableFlags::DETACH => new TranslatableMarkup('Keep their copy, detached'),
        NotSyncableFlags::REMOVE => new TranslatableMarkup('Delete their copy'),
      ],
      '#default_value' => $control->defaultBehavior(),
      '#states' => $visible,
    ];
    return $elements;
  }

  /**
   * Applies submitted values from elements().
   *
   * @return bool
   *   Whether anything changed.
   */
  public static function apply(ContentEntityInterface $entity, array $values, NotSyncableFlags $flags, ItemSyncControl $control): bool {
    $current = $entity->isNew() ? [] : $flags->get($entity->getEntityTypeId(), (string) $entity->uuid());
    if (empty($values['not_syncable'])) {
      if ($current === []) {
        return FALSE;
      }
      $control->markSyncable($entity);
      return TRUE;
    }
    $wanted = ($values['scope'] ?? 'all') === 'selected'
      ? array_values(array_filter((array) ($values['replicas'] ?? [])))
      : [NotSyncableFlags::ALL];
    if ($wanted === []) {
      $wanted = [NotSyncableFlags::ALL];
    }
    $remove = array_values(array_diff(array_keys($current), $wanted));
    $add = array_values(array_diff($wanted, array_keys($current)));
    if ($remove === [] && $add === []) {
      return FALSE;
    }
    // Add first, so narrowing from all replicas to some never lets an update
    // through to the ones that stay excluded.
    if ($add !== []) {
      $control->markNotSyncable($entity, $add === [NotSyncableFlags::ALL] ? NULL : $add, (string) ($values['behavior'] ?? $control->defaultBehavior()));
    }
    if ($remove !== []) {
      $control->markSyncable($entity, $remove);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $form_state->get('entity');
    if (self::apply($entity, $form_state->getValues(), $this->flags, $this->control)) {
      $this->messenger()->addStatus($this->t('Content Relay settings for %label saved.', ['%label' => $entity->label()]));
    }
  }

}
