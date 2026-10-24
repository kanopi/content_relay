<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Event\Operation;
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
   *
   * @return array<string, int>
   *   New row IDs by replica ID.
   */
  public function enqueue(Operation $operation, string $entity_type_id, string $bundle, string $uuid, ?int $source_revision = NULL, ?array $replica_ids = NULL, ?int $uid = NULL, ?int $replay_of = NULL): array {
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
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }
    return $ids;
  }

  /**
   * A row by ID.
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
