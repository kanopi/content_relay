<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Report;

use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Recomputes the whole status report, in chunks.
 *
 * Saves, deliveries and flags keep the rows of the items they touch current.
 * What changes many items at once, a new replica, a contract version or the
 * published bundles, is caught by cron comparing a fingerprint of those, and
 * rebuilds the report from a queue. "Refresh status" in the report does the
 * same through the Batch API.
 */
class ReportRebuild {

  public const QUEUE = 'content_relay_origin_report';

  public const STATE_FINGERPRINT = 'content_relay_origin.report_fingerprint';

  /**
   * Entities per chunk.
   */
  public const CHUNK = 50;

  public function __construct(
    protected ReportIndex $index,
    protected SyncScope $scope,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
  ) {}

  /**
   * Queues a rebuild if what decides the report's rows has changed.
   *
   * @return bool
   *   TRUE if a rebuild was queued.
   */
  public function rebuildIfChanged(): bool {
    $fingerprint = $this->fingerprint();
    if ($this->state->get(self::STATE_FINGERPRINT) === $fingerprint) {
      return FALSE;
    }
    $this->queueRebuild();
    $this->state->set(self::STATE_FINGERPRINT, $fingerprint);
    return TRUE;
  }

  /**
   * Queues a rebuild of every published bundle.
   */
  public function queueRebuild(): void {
    $this->removeUnpublished();
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($this->scope->getPublishedBundles() as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        $queue->createItem(['entity_type' => $entity_type_id, 'bundle' => $bundle, 'after' => NULL]);
      }
    }
  }

  /**
   * Rebuilds one chunk.
   *
   * @return int|string|null
   *   The last entity ID done, or NULL when the bundle is finished.
   */
  public function rebuildChunk(string $entity_type_id, string $bundle, int|string|null $after): int|string|null {
    $ids = $this->index->entityIds($entity_type_id, $bundle, $after, self::CHUNK);
    if ($ids === []) {
      return NULL;
    }
    $this->index->refreshMultiple($entity_type_id, array_values($ids));
    return count($ids) < self::CHUNK ? NULL : end($ids);
  }

  /**
   * The batch definition for "Refresh status".
   */
  public function batch(): array {
    $this->removeUnpublished();
    $operations = [];
    foreach ($this->scope->getPublishedBundles() as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        $operations[] = [[static::class, 'batchStep'], [$entity_type_id, $bundle]];
      }
    }
    $this->state->set(self::STATE_FINGERPRINT, $this->fingerprint());
    return [
      'title' => new TranslatableMarkup('Refreshing sync status'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
    ];
  }

  /**
   * Batch operation: one chunk of a bundle per call.
   */
  public static function batchStep(string $entity_type_id, string $bundle, &$context): void {
    $after = $context['sandbox']['after'] ?? NULL;
    $next = \Drupal::service('content_relay_origin.report_rebuild')->rebuildChunk($entity_type_id, $bundle, $after);
    $context['results']['bundles'][$entity_type_id . ':' . $bundle] = TRUE;
    if ($next === NULL) {
      $context['finished'] = 1;
      return;
    }
    $context['sandbox']['after'] = $next;
    $context['finished'] = 0.5;
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results): void {
    if ($success) {
      \Drupal::messenger()->addStatus(new TranslatableMarkup('Sync status refreshed for @count bundle(s).', ['@count' => count($results['bundles'] ?? [])]));
    }
    else {
      \Drupal::messenger()->addError(new TranslatableMarkup('Refreshing sync status did not finish.'));
    }
  }

  /**
   * Removes rows of bundles no longer published and replicas that are gone.
   */
  protected function removeUnpublished(): void {
    $published = $this->scope->getPublishedBundles();
    $present = $this->database->select(ReportIndex::TABLE, 'r')
      ->fields('r', ['entity_type', 'bundle'])
      ->distinct()
      ->execute()
      ->fetchAll();
    foreach ($present as $row) {
      if (!in_array($row->bundle, $published[$row->entity_type] ?? [], TRUE)) {
        $this->database->delete(ReportIndex::TABLE)
          ->condition('entity_type', $row->entity_type)
          ->condition('bundle', $row->bundle)
          ->execute();
      }
    }
    $replicas = array_keys(array_filter($this->registry->getAll(), fn ($replica) => $replica->receivesEvents()));
    $delete = $this->database->delete(ReportIndex::TABLE);
    if ($replicas !== []) {
      $delete->condition('replica_id', $replicas, 'NOT IN');
    }
    $delete->execute();
  }

  /**
   * What the report's rows depend on beyond single items.
   */
  protected function fingerprint(): string {
    $parts = ['bundles' => $this->scope->getPublishedBundles()];
    foreach ($this->registry->getAll() as $replica) {
      $parts['replicas'][$replica->id] = [
        $replica->status,
        $this->contracts->getActive($replica->id)?->version(),
        $this->contracts->getPending($replica->id)?->version(),
      ];
    }
    return hash('sha256', serialize($parts));
  }

}
