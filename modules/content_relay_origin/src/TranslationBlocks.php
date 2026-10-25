<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Single translations kept from replicas.
 *
 * Either side can stop one translation of an item rather than the whole item:
 * the origin marks it not syncable (for every replica or one), or a replica
 * detaches it. Events then leave the translation out and name it as withheld,
 * so the replica keeps what it has. Each side can only lift its own block.
 */
class TranslationBlocks {

  public const TABLE = 'content_relay_translation_block';

  /**
   * Who set a block: the origin (not syncable) or the replica (detached).
   */
  public const ORIGIN = 'origin';
  public const REPLICA = 'replica';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Blocks a translation.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string $langcode
   *   The translation.
   * @param string $replica_id
   *   A replica, or NotSyncableFlags::ALL for every replica (origin only).
   * @param string $side
   *   self::ORIGIN or self::REPLICA.
   * @param string|null $by
   *   Who did it, by name.
   */
  public function set(string $entity_type_id, string $uuid, string $langcode, string $replica_id, string $side, ?string $by): void {
    $this->database->merge(self::TABLE)
      ->keys([
        'entity_type' => $entity_type_id,
        'uuid' => $uuid,
        'langcode' => $langcode,
        'replica_id' => $replica_id,
        'side' => $side,
      ])
      ->fields(['by_name' => $by === NULL ? NULL : mb_substr($by, 0, 255), 'created' => $this->time->getCurrentTime()])
      ->execute();
  }

  /**
   * Lifts a block.
   *
   * @return bool
   *   TRUE if there was one.
   */
  public function clear(string $entity_type_id, string $uuid, string $langcode, string $replica_id, string $side): bool {
    return $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->condition('langcode', $langcode)
      ->condition('replica_id', $replica_id)
      ->condition('side', $side)
      ->execute() > 0;
  }

  /**
   * The translations of an item kept from a replica, with who kept them.
   *
   * @return array<string, array{side: string, by: string|null, created: int}>
   *   By langcode. The origin's block wins over the replica's.
   */
  public function forReplica(string $entity_type_id, string $uuid, string $replica_id): array {
    $rows = $this->database->select(self::TABLE, 'b')
      ->fields('b')
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->condition('replica_id', [NotSyncableFlags::ALL, $replica_id], 'IN')
      ->orderBy('side', 'DESC')
      ->execute()
      ->fetchAll();
    $blocked = [];
    // Sorted replica first, so an origin block overwrites it.
    foreach ($rows as $row) {
      $blocked[(string) $row->langcode] = [
        'side' => (string) $row->side,
        'by' => $row->by_name,
        'created' => (int) $row->created,
      ];
    }
    return $blocked;
  }

  /**
   * The translations kept from a replica, for many items of one type.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string[] $uuids
   *   UUIDs.
   * @param string $replica_id
   *   The replica.
   *
   * @return array<string, string[]>
   *   Langcodes by UUID, for items that have any.
   */
  public function withheldAmong(string $entity_type_id, array $uuids, string $replica_id): array {
    if ($uuids === []) {
      return [];
    }
    $withheld = [];
    $rows = $this->database->select(self::TABLE, 'b')
      ->fields('b', ['uuid', 'langcode'])
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuids, 'IN')
      ->condition('replica_id', [NotSyncableFlags::ALL, $replica_id], 'IN')
      ->execute()
      ->fetchAll();
    foreach ($rows as $row) {
      $withheld[(string) $row->uuid][] = (string) $row->langcode;
    }
    return array_map(fn (array $langcodes) => array_values(array_unique($langcodes)), $withheld);
  }

  /**
   * Removes every block on an item, when it is deleted.
   */
  public function forget(string $entity_type_id, string $uuid): void {
    $this->database->delete(self::TABLE)->condition('entity_type', $entity_type_id)->condition('uuid', $uuid)->execute();
  }

}
