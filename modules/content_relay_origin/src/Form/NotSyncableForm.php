<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay_origin\ItemSyncControl;
use Drupal\content_relay_origin\ManualPush;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\TranslationBlocks;
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
    protected TranslationBlocks $translationBlocks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.item_sync_control'),
      $container->get('content_relay_origin.not_syncable'),
      $container->get('content_relay_origin.manual_push'),
      $container->get('content_relay_origin.translation_blocks'),
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
    $languages = $entity->getTranslationLanguages();
    if (count($languages) > 1) {
      $options = [];
      foreach ($languages as $langcode => $language) {
        $options[$langcode] = $language->getName();
      }
      $form['translations'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Stop syncing only these translations'),
        '#description' => $this->t('To every replica. Replicas keep what they have of them; the rest of the item keeps syncing.'),
        '#options' => $options,
        '#default_value' => $this->blockedTranslations($entity),
        '#states' => ['visible' => [':input[name="sync"]' => ['value' => 'all']]],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * The sync elements, shared with the entity form.
   *
   * @return array
   *   Form elements: sync, replicas and behavior.
   */
  public static function elements(ContentEntityInterface $entity, NotSyncableFlags $flags, ManualPush $push, ItemSyncControl $control): array {
    $current = $entity->isNew() ? [] : $flags->get($entity->getEntityTypeId(), (string) $entity->uuid());
    $replicas = [];
    foreach ($push->targets($entity) as $replica_id => $replica) {
      $replicas[$replica_id] = $replica->label;
    }
    $options = [
      'all' => new TranslatableMarkup('Every replica'),
      'none' => new TranslatableMarkup('No replicas'),
    ];
    if ($replicas !== []) {
      $options['except'] = new TranslatableMarkup('Every replica except');
    }
    $sync = match (TRUE) {
      $current === [] => 'all',
      isset($current[NotSyncableFlags::ALL]) || $replicas === [] => 'none',
      default => 'except',
    };
    $elements['sync'] = [
      '#type' => 'radios',
      '#title' => new TranslatableMarkup('Sync to replicas'),
      '#options' => $options,
      '#default_value' => $sync,
    ];
    $elements['replicas'] = [
      '#type' => 'checkboxes',
      '#title' => new TranslatableMarkup('Replicas that do not get it'),
      '#title_display' => 'invisible',
      '#options' => $replicas,
      '#default_value' => array_values(array_diff(array_keys($current), [NotSyncableFlags::ALL])),
      '#states' => ['visible' => [':input[name="sync"]' => ['value' => 'except']]],
      '#access' => $replicas !== [],
    ];
    $elements['behavior'] = [
      '#type' => 'radios',
      '#title' => new TranslatableMarkup('Replicas that already have it'),
      '#options' => [
        NotSyncableFlags::DETACH => new TranslatableMarkup('Keep their copy, no longer updated'),
        NotSyncableFlags::REMOVE => new TranslatableMarkup('Delete their copy'),
      ],
      '#default_value' => $control->defaultBehavior(),
      '#states' => ['invisible' => [':input[name="sync"]' => ['value' => 'all']]],
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
    $wanted = match ($values['sync'] ?? 'all') {
      'none' => [NotSyncableFlags::ALL],
      // Every replica except none of them is every replica.
      'except' => array_values(array_filter((array) ($values['replicas'] ?? []))),
      default => [],
    };
    if ($wanted === []) {
      if ($current === []) {
        return FALSE;
      }
      $control->markSyncable($entity);
      return TRUE;
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
    $changed = self::apply($entity, $form_state->getValues(), $this->flags, $this->control);
    if (is_array($form_state->getValue('translations'))) {
      $before = $this->blockedTranslations($entity);
      $after = array_values(array_filter($form_state->getValue('translations')));
      foreach (array_diff($after, $before) as $langcode) {
        $this->control->setTranslationSyncable($entity, (string) $langcode, FALSE);
        $changed = TRUE;
      }
      foreach (array_diff($before, $after) as $langcode) {
        $this->control->setTranslationSyncable($entity, (string) $langcode, TRUE);
        $changed = TRUE;
      }
    }
    if ($changed) {
      $this->messenger()->addStatus($this->t('Content Relay settings for %label saved.', ['%label' => $entity->label()]));
    }
  }

  /**
   * Translations of an item this site keeps from every replica.
   *
   * @return string[]
   *   Langcodes.
   */
  protected function blockedTranslations(ContentEntityInterface $entity): array {
    $blocks = $this->translationBlocks->forReplica($entity->getEntityTypeId(), (string) $entity->uuid(), NotSyncableFlags::ALL);
    return array_keys(array_filter($blocks, fn (array $block) => $block['side'] === TranslationBlocks::ORIGIN));
  }

}
