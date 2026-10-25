<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\Core\Database\Connection;

/**
 * What each replica has of each item, as far as the origin knows.
 *
 * Updated from every answer a replica gives. Delivery reads it to know which
 * dependencies a replica still needs, and the status report reads it for
 * each replica's acknowledged hashes.
 *
 * Whether the replica detached an item is kept apart from its status, so
 * reattaching finds the item as it was.
 */
class ReplicaItems {

  public const TABLE = 'content_relay_replica_item';

  public const APPLIED = 'applied';
  public const ACCEPTED = 'accepted';
  public const FAILED = 'failed';
  public const DELETED = 'deleted';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Records a replica's answer to an event.
   *
   * @param string $replica_id
   *   The replica.
   * @param \Drupal\content_relay\Event\SyncEvent $event
   *   The event it answered.
   * @param string $status
   *   One of the class constants.
   */
  public function record(string $replica_id, SyncEvent $event, string $status): void {
    $fields = [
      'bundle' => $event->bundle,
      'status' => $event->operation === Operation::Delete && $status === self::APPLIED ? self::DELETED : $status,
      'last_event_id' => $event->eventId,
      'updated' => $this->time->getCurrentTime(),
    ];
    if ($fields['status'] === self::DELETED) {
      $fields += ['detached_at' => NULL, 'detached_by' => NULL];
    }
    if ($event->hashes !== NULL && $status === self::APPLIED) {
      $fields += [
        'revision' => $event->sourceRevision,
        'algorithm' => $event->hashes->algorithmVersion,
        'entity_hash' => $event->hashes->entity,
        'translation_hashes' => json_encode($event->hashes->translations, \JSON_THROW_ON_ERROR),
      ];
    }
    $this->database->merge(self::TABLE)
      ->keys(['replica_id' => $replica_id, 'entity_type' => $event->entityType, 'uuid' => $event->entityUuid])
      ->fields($fields)
      ->execute();
  }

  /**
   * Whether a replica has, or is about to have, an item.
   *
   * An item it accepted or failed to save still counts: it holds the event,
   * and sending the item again would not change the outcome.
   */
  public function isKnown(string $replica_id, string $entity_type_id, string $uuid): bool {
    $status = $this->database->select(self::TABLE, 'i')
      ->fields('i', ['status'])
      ->condition('replica_id', $replica_id)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchField();
    return $status !== FALSE && $status !== self::DELETED;
  }

  /**
   * The record for an item on a replica.
   */
  public function get(string $replica_id, string $entity_type_id, string $uuid): ?array {
    return $this->database->select(self::TABLE, 'i')
      ->fields('i')
      ->condition('replica_id', $replica_id)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * Records that a replica detached an item.
   *
   * @param string $replica_id
   *   The replica.
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string $bundle
   *   Bundle, for an item the origin has no record of on that replica.
   * @param string|null $by
   *   Who detached it, as the replica reported.
   * @param int|null $at
   *   When, as the replica reported; now if not.
   *
   * @return bool
   *   TRUE if the item was not already recorded as detached.
   */
  public function markDetached(string $replica_id, string $entity_type_id, string $uuid, string $bundle, ?string $by, ?int $at = NULL): bool {
    $was = $this->isDetached($replica_id, $entity_type_id, $uuid);
    $fields = [
      'detached_at' => $at ?? $this->time->getCurrentTime(),
      'detached_by' => $by === NULL ? NULL : mb_substr($by, 0, 255),
    ];
    $this->database->merge(self::TABLE)
      ->keys(['replica_id' => $replica_id, 'entity_type' => $entity_type_id, 'uuid' => $uuid])
      ->insertFields($fields + [
        'bundle' => $bundle,
        'status' => self::APPLIED,
        'last_event_id' => '',
        'updated' => $this->time->getCurrentTime(),
      ])
      ->updateFields($fields)
      ->execute();
    return !$was;
  }

  /**
   * Records that a replica reattached an item.
   *
   * @return bool
   *   TRUE if it was recorded as detached.
   */
  public function markAttached(string $replica_id, string $entity_type_id, string $uuid): bool {
    return (bool) $this->database->update(self::TABLE)
      ->fields(['detached_at' => NULL, 'detached_by' => NULL])
      ->condition('replica_id', $replica_id)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->isNotNull('detached_at')
      ->execute();
  }

  /**
   * Whether a replica detached an item.
   */
  public function isDetached(string $replica_id, string $entity_type_id, string $uuid): bool {
    return ($this->get($replica_id, $entity_type_id, $uuid)['detached_at'] ?? NULL) !== NULL;
  }

}
