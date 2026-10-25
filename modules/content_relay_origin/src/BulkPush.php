<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;

/**
 * Pushes many items at once, from the status report or Drush.
 *
 * Works on report rows: a selection, or every row matching the report's
 * filters. Rows are one per translation, so a selection is first reduced to
 * one push per item and replica, optionally keeping only rows in some
 * languages. Items not syncable for a replica, or detached by it, are left
 * out and counted.
 *
 * Pushing only records events in the outgoing queue, in batches through the
 * Batch API; delivery happens as for any other event. Nothing is sent during
 * the request that starts it.
 */
class BulkPush {

  use StringTranslationTrait;

  /**
   * Items recorded per batch step.
   */
  public const BATCH_SIZE = 50;

  public function __construct(
    protected Connection $database,
    protected ItemSyncControl $control,
    protected Outbox $outbox,
    protected ReplicaItems $items,
    protected HashStore $hashStore,
    protected DeliveryScheduler $scheduler,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Reduces report rows to the pushes they stand for.
   *
   * @param int[] $row_ids
   *   Report row IDs.
   * @param string[]|null $langcodes
   *   Keep only rows in these languages; NULL for all.
   *
   * @return array{items: array<array{entity_type: string, uuid: string, bundle: string, replica_id: string}>, skipped: array<string, array<string, int>>}
   *   Pushes, one per item and replica, and by replica the number of items
   *   left out, by reason.
   */
  public function plan(array $row_ids, ?array $langcodes = NULL): array {
    $items = $skipped = [];
    foreach (array_chunk(array_values(array_unique($row_ids)), 1000) as $chunk) {
      $query = $this->database->select(ReportIndex::TABLE, 'r')
        ->fields('r', ['entity_type', 'uuid', 'bundle', 'replica_id', 'status'])
        ->condition('id', $chunk, 'IN');
      if ($langcodes !== NULL) {
        $query->condition('langcode', $langcodes ?: [''], 'IN');
      }
      foreach ($query->execute() as $row) {
        $key = $row->replica_id . '|' . $row->entity_type . '|' . $row->uuid;
        if (isset($items[$key]) || isset($skipped[$row->replica_id][$key])) {
          continue;
        }
        if (in_array($row->status, [ReportIndex::NOT_SYNCABLE, ReportIndex::DETACHED_BY_REPLICA], TRUE)) {
          $skipped[$row->replica_id][$key] = $row->status;
          continue;
        }
        $items[$key] = [
          'entity_type' => $row->entity_type,
          'uuid' => $row->uuid,
          'bundle' => $row->bundle,
          'replica_id' => $row->replica_id,
        ];
      }
    }
    $counts = [];
    foreach ($skipped as $replica_id => $reasons) {
      $counts[$replica_id] = array_count_values($reasons);
    }
    return ['items' => array_values($items), 'skipped' => $counts];
  }

  /**
   * Counts planned pushes by replica.
   *
   * @return array<string, int>
   *   Counts.
   */
  public static function countByReplica(array $items): array {
    $counts = [];
    foreach ($items as $item) {
      $counts[$item['replica_id']] = ($counts[$item['replica_id']] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
  }

  /**
   * Records one push.
   *
   * @return string
   *   "queued", or why it was not: not_syncable, detached_by_replica,
   *   not_in_contract or gone.
   */
  public function queue(array $item, string $reason = Outbox::REASON_BULK): string {
    $blocked = $this->control->blockReason($item['replica_id'], $item['entity_type'], $item['uuid'], $item['bundle']);
    if ($blocked !== NULL) {
      return $blocked;
    }
    $latest = $this->hashStore->latest($item['entity_type'], $item['uuid']);
    if ($latest === NULL) {
      return 'gone';
    }
    $operation = $this->items->isKnown($item['replica_id'], $item['entity_type'], $item['uuid']) ? Operation::Update : Operation::Create;
    $ids = $this->outbox->enqueue($operation, $item['entity_type'], $item['bundle'], $item['uuid'], $latest['revision'], [$item['replica_id']], (int) $this->currentUser->id(), reason: $reason);
    return isset($ids[$item['replica_id']]) ? 'queued' : 'not_in_contract';
  }

  /**
   * Records many pushes and schedules delivery.
   *
   * @return array<string, array<string, int>>
   *   By replica, counts of outcomes.
   */
  public function queueMultiple(array $items, string $reason = Outbox::REASON_BULK): array {
    $outcomes = [];
    foreach ($items as $item) {
      $outcome = $this->queue($item, $reason);
      $outcomes[$item['replica_id']][$outcome] = ($outcomes[$item['replica_id']][$outcome] ?? 0) + 1;
    }
    $this->scheduler->schedule(array_keys($outcomes));
    return $outcomes;
  }

  /**
   * The batch definition for a planned push.
   */
  public function batch(array $items): array {
    $operations = [];
    foreach (array_chunk($items, self::BATCH_SIZE) as $chunk) {
      $operations[] = [[static::class, 'batchStep'], [$chunk]];
    }
    $this->logger->notice('@user started a bulk push of @count item(s): @replicas.', [
      '@user' => $this->currentUser->getAccountName(),
      '@count' => count($items),
      '@replicas' => json_encode(self::countByReplica($items)),
    ]);
    return [
      'title' => $this->t('Pushing items'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
      'progress_message' => $this->t('Recorded @current of @total batches.'),
    ];
  }

  /**
   * Batch operation: records a chunk of pushes.
   */
  public static function batchStep(array $items, &$context): void {
    $outcomes = \Drupal::service('content_relay_origin.bulk_push')->queueMultiple($items);
    foreach ($outcomes as $replica_id => $counts) {
      foreach ($counts as $outcome => $count) {
        $context['results'][$replica_id][$outcome] = ($context['results'][$replica_id][$outcome] ?? 0) + $count;
      }
    }
  }

  /**
   * Batch finished callback: a summary per replica.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The bulk push did not finish. Items recorded before the error will still be sent.'));
    }
    $labels = array_map(fn ($replica) => $replica->label, \Drupal::service('content_relay_origin.replica_registry')->getAll());
    foreach (static::summarize($results, $labels) as $line) {
      $messenger->addStatus($line);
    }
  }

  /**
   * Summary lines, one per replica.
   *
   * @param array<string, array<string, int>> $results
   *   Outcome counts by replica.
   * @param array<string, string> $labels
   *   Replica labels by ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Lines.
   */
  public static function summarize(array $results, array $labels = []): array {
    $lines = [];
    ksort($results);
    foreach ($results as $replica_id => $counts) {
      $queued = $counts['queued'] ?? 0;
      unset($counts['queued']);
      $lines[] = new TranslatableMarkup('@replica: @queued queued@skipped.', [
        '@replica' => $labels[$replica_id] ?? $replica_id,
        '@queued' => $queued,
        '@skipped' => $counts === [] ? '' : ', ' . implode(', ', array_map(fn ($reason, $count) => "$count $reason", array_keys($counts), $counts)) . ' skipped',
      ]);
    }
    return $lines;
  }

}
