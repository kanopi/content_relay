<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\ProcessingMode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * The outgoing event log: one row per event per replica.
 *
 * A row records that a change happened and must reach a replica, not what the
 * change was. The snapshot is built when the row is sent, from the entity as
 * it is then. That keeps saves cheap, means a retry or replay never sends an
 * outdated payload, and lets several queued updates of one entity go out as
 * one.
 */
class Outbox {

  public const TABLE = 'content_relay_outbox';

  /**
   * Pushed by hand from the item's push form or Drush.
   */
  public const REASON_MANUAL = 'manual';

  /**
   * Pushed from the status report or Drush, in bulk.
   */
  public const REASON_BULK = 'bulk';

  /**
   * Sent because the item was marked not syncable.
   *
   * A delete recorded for this reason removes the replica's copy of an item
   * the origin still has.
   */
  public const REASON_NOT_SYNCABLE = 'not_syncable';

  /**
   * Sent because the item was marked syncable again.
   */
  public const REASON_ATTACH = 'attach';

  /**
   * Re-sent by reconciliation, because the replica did not have it.
   */
  public const REASON_RECONCILE = 'reconcile';

  public function __construct(
    protected Connection $database,
    protected ReplicaRegistry $registry,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected UuidInterface $uuid,
  ) {}

  /**
   * Records a change for every replica that receives events.
   *
   * @param \Drupal\content_relay\Event\Operation $operation
   *   What happened.
   * @param string $entity_type_id
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string $uuid
   *   Entity UUID.
   * @param int|null $source_revision
   *   The revision or version at the time, for deletes, which cannot be read
   *   from the entity later.
   * @param string[]|null $replica_ids
   *   Only these replicas. NULL for all that receive events.
   * @param int|null $uid
   *   The user who triggered it, for manual actions.
   * @param int|null $replay_of
   *   The row this replays.
   * @param string|null $reason
   *   Why, when not an entity save: one of the REASON_* constants.
   * @param \Drupal\content_relay\Event\ProcessingMode|null $process
   *   A processing mode for the replica to use instead of its own.
   *
   * @return array<string, int>
   *   New row IDs by replica ID.
   */
  public function enqueue(Operation $operation, string $entity_type_id, string $bundle, string $uuid, ?int $source_revision = NULL, ?array $replica_ids = NULL, ?int $uid = NULL, ?int $replay_of = NULL, ?string $reason = NULL, ?ProcessingMode $process = NULL): array {
    $now = $this->time->getCurrentTime();
    $ids = [];
    foreach ($this->registry->getAll() as $replica) {
      if (!$replica->receivesEvents() || ($replica_ids !== NULL && !in_array($replica->id, $replica_ids, TRUE))) {
        continue;
      }
      $ids[$replica->id] = (int) $this->database->insert(self::TABLE)
        ->fields([
          'event_id' => $this->uuid->generate(),
          'replica_id' => $replica->id,
          'sequence' => $this->registry->takeSequence($replica->id),
          'operation' => $operation->value,
          'entity_type' => $entity_type_id,
          'bundle' => $bundle,
          'entity_uuid' => $uuid,
          'source_revision' => $source_revision,
          'status' => EventStatus::QUEUED,
          'attempts' => 0,
          'next_attempt' => $now,
          'triggered_by' => $uid,
          'replay_of' => $replay_of,
          'reason' => $reason,
          'process' => $process?->value,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }
    return $ids;
  }

  /**
   * A row by ID.
   *
   * @phpstan-impure
   */
  public function load(int $id): ?array {
    return $this->database->select(self::TABLE, 'o')->fields('o')->condition('id', $id)->execute()->fetchAssoc() ?: NULL;
  }

  /**
   * The oldest unsent row for a replica, due or not.
   *
   * Delivery is strictly in sequence order per replica: when the oldest is
   * waiting for a retry, nothing behind it is sent.
   */
  public function nextPending(string $replica_id): ?array {
    return $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('replica_id', $replica_id)
      ->condition('status', EventStatus::PENDING, 'IN')
      ->orderBy('sequence')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * Rows a replica accepted and has not yet said how they ended, oldest first.
   *
   * @return array[]
   *   Rows.
   */
  public function acceptedRows(string $replica_id, int $limit): array {
    return array_map(fn (object $row) => (array) $row, $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('replica_id', $replica_id)
      ->condition('status', EventStatus::ACCEPTED)
      ->orderBy('sequence')
      ->range(0, $limit)
      ->execute()
      ->fetchAll());
  }

  /**
   * Replicas with accepted rows.
   *
   * @return string[]
   *   Replica IDs.
   */
  public function replicasWithAcceptedRows(): array {
    return $this->database->select(self::TABLE, 'o')
      ->fields('o', ['replica_id'])
      ->condition('status', EventStatus::ACCEPTED)
      ->distinct()
      ->execute()
      ->fetchCol();
  }

  /**
   * The number of events in each state, for one replica.
   *
   * @return array<string, int>
   *   Counts by status.
   */
  public function countsByStatus(string $replica_id): array {
    $query = $this->database->select(self::TABLE, 'o')->condition('replica_id', $replica_id);
    $query->addField('o', 'status');
    $query->addExpression('COUNT(*)', 'total');
    $counts = [];
    foreach ($query->groupBy('o.status')->execute()->fetchAll() as $row) {
      $counts[(string) $row->status] = (int) $row->total;
    }
    ksort($counts);
    return $counts;
  }

  /**
   * A replica's rows with given sequence numbers.
   *
   * @param string $replica_id
   *   The replica.
   * @param int[] $sequences
   *   Sequence numbers.
   *
   * @return array<int, array>
   *   Rows by sequence.
   */
  public function bySequence(string $replica_id, array $sequences): array {
    if ($sequences === []) {
      return [];
    }
    $rows = [];
    $result = $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('replica_id', $replica_id)
      ->condition('sequence', $sequences, 'IN')
      ->execute()
      ->fetchAll();
    foreach ($result as $row) {
      $rows[(int) $row->sequence] = (array) $row;
    }
    return $rows;
  }

  /**
   * The oldest unsent row for an item and a replica.
   */
  public function pendingRowFor(string $replica_id, string $entity_type_id, string $uuid): ?array {
    return $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('replica_id', $replica_id)
      ->condition('entity_type', $entity_type_id)
      ->condition('entity_uuid', $uuid)
      ->condition('status', EventStatus::PENDING, 'IN')
      ->orderBy('sequence')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * The latest row for an item and a replica that is or was meant to be sent.
   *
   * Superseded and replayed rows are left out: another row speaks for them.
   */
  public function latestFor(string $replica_id, string $entity_type_id, string $uuid): ?array {
    return $this->database->select(self::TABLE, 'o')
      ->fields('o')
      ->condition('replica_id', $replica_id)
      ->condition('entity_type', $entity_type_id)
      ->condition('entity_uuid', $uuid)
      ->condition('status', [EventStatus::SUPERSEDED, EventStatus::REPLAYED], 'NOT IN')
      ->orderBy('sequence', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * The time of the last answer a replica gave that settled an item.
   */
  public function lastSuccessFor(string $replica_id, string $entity_type_id, string $uuid): ?int {
    $query = $this->database->select(self::TABLE, 'o')
      ->condition('replica_id', $replica_id)
      ->condition('entity_type', $entity_type_id)
      ->condition('entity_uuid', $uuid)
      ->condition('status', [EventStatus::APPLIED, EventStatus::ACCEPTED, EventStatus::STALE], 'IN');
    $query->addExpression('MAX(changed)', 'last');
    $value = $query->execute()->fetchField();
    return $value === NULL || $value === FALSE ? NULL : (int) $value;
  }

  /**
   * The sequence of the last event this replica was sent before another.
   */
  public function previousSentSequence(string $replica_id, int $sequence): ?int {
    $previous = $this->database->select(self::TABLE, 'o')
      ->condition('replica_id', $replica_id)
      ->condition('sequence', $sequence, '<')
      ->condition('attempts', 0, '>')
      ->condition('status', EventStatus::SUPERSEDED, '<>');
    $previous->addExpression('MAX(sequence)', 'previous');
    $value = $previous->execute()->fetchField();
    return $value === NULL || $value === FALSE ? NULL : (int) $value;
  }

  /**
   * The highest sequence ever sent to a replica, or 0.
   */
  public function lastSentSequence(string $replica_id): int {
    return $this->previousSentSequence($replica_id, \PHP_INT_MAX) ?? 0;
  }

  /**
   * How many events were first sent to a replica since a moment.
   */
  public function countSentSince(string $replica_id, int $since): int {
    return (int) $this->database->select(self::TABLE, 'o')
      ->condition('replica_id', $replica_id)
      ->condition('first_sent', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Marks later pending creates and updates of an entity as superseded.
   *
   * Called when an event for the entity is about to be sent with its current
   * state, which already includes whatever those later rows recorded.
   *
   * @return int
   *   How many rows were superseded.
   */
  public function supersedeLater(array $row): int {
    return (int) $this->database->update(self::TABLE)
      ->fields([
        'status' => EventStatus::SUPERSEDED,
        'changed' => $this->time->getCurrentTime(),
        'completed' => $this->time->getCurrentTime(),
      ])
      ->condition('replica_id', $row['replica_id'])
      ->condition('entity_type', $row['entity_type'])
      ->condition('entity_uuid', $row['entity_uuid'])
      ->condition('sequence', $row['sequence'], '>')
      ->condition('operation', [Operation::Create->value, Operation::Update->value], 'IN')
      ->condition('status', EventStatus::QUEUED)
      // A manual push is sent as asked, with its own processing mode, even
      // when an earlier event already carries the same state.
      ->condition($this->database->condition('OR')->isNull('reason')->condition('reason', self::REASON_MANUAL, '<>'))
      ->execute();
  }

  /**
   * Updates a row.
   *
   * @param int $id
   *   Row ID.
   * @param array $fields
   *   Column values. Final states also set "completed".
   */
  public function update(int $id, array $fields): void {
    $now = $this->time->getCurrentTime();
    $fields['changed'] = $now;
    if (isset($fields['status']) && !in_array($fields['status'], [...EventStatus::PENDING, EventStatus::ACCEPTED], TRUE)) {
      $fields['completed'] = $now;
    }
    $this->database->update(self::TABLE)->fields($fields)->condition('id', $id)->execute();
  }

  /**
   * Replicas with a pending row due now.
   *
   * @return string[]
   *   Replica IDs.
   */
  public function replicasWithDueEvents(): array {
    return $this->database->select(self::TABLE, 'o')
      ->fields('o', ['replica_id'])
      ->condition('status', EventStatus::PENDING, 'IN')
      ->condition('next_attempt', $this->time->getCurrentTime(), '<=')
      ->distinct()
      ->execute()
      ->fetchCol();
  }

  /**
   * Removes acknowledged rows older than the retention period.
   *
   * Failures, dead letters and rows still waiting for confirmation are kept
   * until they are resolved, however old.
   *
   * @return int
   *   How many rows were removed.
   */
  public function prune(): int {
    $days = (int) ($this->configFactory->get('content_relay_origin.settings')->get('retention_days') ?: 60);
    return (int) $this->database->delete(self::TABLE)
      ->condition('status', EventStatus::PRUNABLE, 'IN')
      ->condition('changed', $this->time->getCurrentTime() - $days * 86400, '<')
      ->execute();
  }

}
