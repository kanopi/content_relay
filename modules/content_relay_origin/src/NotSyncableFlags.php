<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Items marked not syncable, by entity type and UUID.
 *
 * A flag applies to every replica (replica_id '') or to one. Kept in a table
 * of its own rather than a field, so it works for any entity type without
 * changing its schema. This class only stores flags; ItemSyncControl decides
 * what marking and unmarking send.
 */
class NotSyncableFlags {

  public const TABLE = 'content_relay_not_syncable';

  /**
   * Replicas that had the item keep their copy, detached.
   */
  public const DETACH = 'detach';

  /**
   * Replicas that had the item delete it.
   */
  public const REMOVE = 'remove';

  /**
   * The replica_id of a flag that applies to every replica.
   */
  public const ALL = '';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Stores a flag.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string $bundle
   *   Bundle.
   * @param string $replica_id
   *   A replica, or self::ALL.
   * @param string $behavior
   *   self::DETACH or self::REMOVE.
   * @param int $uid
   *   Who marked it.
   */
  public function set(string $entity_type_id, string $uuid, string $bundle, string $replica_id, string $behavior, int $uid): void {
    $this->database->merge(self::TABLE)
      ->keys(['entity_type' => $entity_type_id, 'uuid' => $uuid, 'replica_id' => $replica_id])
      ->fields([
        'bundle' => $bundle,
        'behavior' => $behavior,
        'uid' => $uid,
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();
  }

  /**
   * Removes flags.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string[]|null $replica_ids
   *   Which flags (self::ALL for the one applying to all replicas), or NULL
   *   for every flag on the item.
   */
  public function clear(string $entity_type_id, string $uuid, ?array $replica_ids = NULL): void {
    $query = $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid);
    if ($replica_ids !== NULL) {
      if ($replica_ids === []) {
        return;
      }
      $query->condition('replica_id', $replica_ids, 'IN');
    }
    $query->execute();
  }

  /**
   * The flags on an item, keyed by replica ID (self::ALL for all).
   *
   * @return array<string, array>
   *   Rows.
   */
  public function get(string $entity_type_id, string $uuid): array {
    $rows = $this->database->select(self::TABLE, 'f')
      ->fields('f')
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAllAssoc('replica_id');
    return array_map(fn (object $row) => (array) $row, $rows);
  }

  /**
   * The flag that keeps an item from a replica, or NULL.
   *
   * A flag for every replica wins over one for this replica.
   */
  public function getBlocking(string $entity_type_id, string $uuid, string $replica_id): ?array {
    $flags = $this->get($entity_type_id, $uuid);
    return $flags[self::ALL] ?? $flags[$replica_id] ?? NULL;
  }

  /**
   * Whether an item is kept from a replica.
   */
  public function isBlocked(string $entity_type_id, string $uuid, string $replica_id): bool {
    return $this->getBlocking($entity_type_id, $uuid, $replica_id) !== NULL;
  }

  /**
   * Which of many items are kept from a replica.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string[] $uuids
   *   UUIDs.
   * @param string $replica_id
   *   The replica.
   *
   * @return array<string, true>
   *   The blocked UUIDs, as keys.
   */
  public function blockedAmong(string $entity_type_id, array $uuids, string $replica_id): array {
    if ($uuids === []) {
      return [];
    }
    $blocked = $this->database->select(self::TABLE, 'f')
      ->fields('f', ['uuid'])
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuids, 'IN')
      ->condition('replica_id', [self::ALL, $replica_id], 'IN')
      ->execute()
      ->fetchCol();
    return array_fill_keys(array_map('strval', $blocked), TRUE);
  }

}
