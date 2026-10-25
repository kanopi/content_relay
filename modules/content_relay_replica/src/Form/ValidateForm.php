<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\Validator;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Recomputes one item's hashes here and on the origin, and compares them.
 */
final class ValidateForm extends FormBase {

  public function __construct(
    protected Validator $validator,
    protected SyncStateStore $syncState,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.validator'),
      $container->get('content_relay_replica.sync_state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_validate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL, ?string $uuid = NULL): array {
    if ($entity_type_id === NULL || $uuid === NULL || $this->syncState->get($entity_type_id, $uuid) === NULL) {
      throw new NotFoundHttpException();
    }
    $form_state->set('item', [$entity_type_id, $uuid]);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Hash the copy here again and compare it with what it should be, and ask the origin for its latest version to compare with what this site received.') . '</p>',
    ];
    $result = $form_state->get('result');
    if (is_array($result)) {
      $origin = match (TRUE) {
        $result['origin'] === NULL => $this->t('Could not ask the origin: @error', ['@error' => $result['origin_error']]),
        $result['origin'] => $this->t('In sync: this site has the latest version (origin revision @revision).', ['@revision' => $result['origin_revision']]),
        default => $this->t('Out of sync: the origin has revision @revision, this site received @received. Translations that differ: @langcodes.', [
          '@revision' => $result['origin_revision'],
          '@received' => $result['received_revision'],
          '@langcodes' => implode(', ', $result['differing_translations']) ?: $this->t('none'),
        ]),
      };
      $form['result'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Result'),
        '#items' => [
          $result['local']
            ? $this->t('This site: the copy hashes as it should.')
            : $this->t('This site: the copy does not hash as it should (expected @expected, found @actual). It was changed here.', [
              '@expected' => substr((string) $result['local_expected'], 0, 12),
              '@actual' => $result['local_actual'] === NULL ? $this->t('no copy') : substr($result['local_actual'], 0, 12),
            ]),
          $origin,
        ],
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Validate')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$entity_type_id, $uuid] = $form_state->get('item');
    $form_state->set('result', $this->validator->validate($entity_type_id, $uuid));
    $form_state->setRebuild();
  }

}
