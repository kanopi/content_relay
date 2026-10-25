<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pulls every synced item of a mapping's origin bundle and applies it again.
 *
 * After a mapping changes, items already here keep what the old mapping
 * made of them. This fetches each from the origin and applies it under the
 * mapping as it is now, through the Batch API.
 */
class MappingReapplyForm extends ConfirmFormBase {

  /**
   * The mapping.
   */
  protected ContentRelayMapping $mapping;

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_mapping_reapply';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentRelayMapping $content_relay_mapping = NULL): array {
    assert($content_relay_mapping !== NULL);
    $this->mapping = $content_relay_mapping;
    $form = parent::buildForm($form, $form_state);
    if ($this->uuids() === []) {
      $form['actions']['submit']['#access'] = FALSE;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->formatPlural(count($this->uuids()), 'Re-apply %label to 1 synced item?', 'Re-apply %label to @count synced items?', ['%label' => $this->mapping->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->uuids() === []
      ? $this->t('No items of this origin bundle have been received yet.')
      : $this->t('Each item is fetched from the origin and saved again under the mapping as it is now, as a new revision. Detached items are left alone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Re-apply');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.content_relay_mapping.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $operations = [];
    foreach (array_chunk($this->uuids(), 10) as $chunk) {
      $operations[] = [[static::class, 'batchStep'], [$this->mapping->getSourceEntityType(), $chunk]];
    }
    batch_set([
      'title' => $this->t('Re-applying %label', ['%label' => $this->mapping->label()]),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
    ]);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Batch operation: pulls and applies a chunk of items.
   */
  public static function batchStep(string $entity_type_id, array $uuids, &$context): void {
    $puller = \Drupal::service('content_relay_replica.puller');
    foreach ($uuids as $uuid) {
      try {
        $result = $puller->pull($entity_type_id, $uuid, TRUE);
        $key = $result->httpStatus === 200 ? 'applied' : 'failed';
      }
      catch (\Throwable) {
        $key = 'failed';
      }
      $context['results'][$key] = ($context['results'][$key] ?? 0) + 1;
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results): void {
    \Drupal::messenger()->addStatus(new TranslatableMarkup('@applied item(s) re-applied, @failed failed (see the replica report).', [
      '@applied' => $results['applied'] ?? 0,
      '@failed' => $results['failed'] ?? 0,
    ]));
  }

  /**
   * UUIDs of the synced items of the mapping's origin bundle, not detached.
   *
   * @return string[]
   *   UUIDs.
   */
  protected function uuids(): array {
    return $this->database->select(SyncStateStore::TABLE, 's')
      ->fields('s', ['uuid'])
      ->condition('entity_type', $this->mapping->getSourceEntityType())
      ->condition('bundle', $this->mapping->getSourceBundle())
      ->isNull('detached_at')
      ->execute()
      ->fetchCol();
  }

}
