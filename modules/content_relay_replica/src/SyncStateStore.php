<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * The sync state table: one row per item this replica received.
 *
 * A row is what makes an item synced. Sync only ever updates or deletes items
 * that have one, so content created on the replica is never touched, even if
 * its UUID happens to match an origin item.
 */
class SyncStateStore {

  public const TABLE = 'content_relay_sync_state';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * The row for an item, or NULL if it is not synced.
   */
  public function get(string $entity_type_id, string $uuid): ?array {
    $row = $this->database->select(self::TABLE, 's')
      ->fields('s')
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['source_translation_hashes'] = json_decode($row['source_translation_hashes'], TRUE) ?: [];
    return $row;
  }

  /**
   * Creates or replaces the row for an item.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param array $fields
   *   Column values. source_translation_hashes may be an array.
   */
  public function save(string $entity_type_id, string $uuid, array $fields): void {
    if (isset($fields['source_translation_hashes']) && is_array($fields['source_translation_hashes'])) {
      $fields['source_translation_hashes'] = json_encode($fields['source_translation_hashes'], \JSON_THROW_ON_ERROR);
    }
    $this->database->merge(self::TABLE)
      ->keys(['entity_type' => $entity_type_id, 'uuid' => $uuid])
      ->fields($fields + ['last_synced' => $this->time->getCurrentTime()])
      ->execute();
  }

  /**
   * Sets an existing row's status and error.
   */
  public function setStatus(string $entity_type_id, string $uuid, string $status, ?string $error = NULL): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => $status, 'last_error' => $error])
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute();
  }

  /**
   * Removes the row of an item deleted through sync.
   */
  public function delete(string $entity_type_id, string $uuid): void {
    $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute();
  }

}
