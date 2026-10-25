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

  /**
   * Columns holding JSON, decoded on read and encoded on save.
   */
  protected const JSON = [
    'source_translation_hashes',
    'local_translation_hashes',
    'detached_translations',
    'withheld_translations',
    'local_translations',
    'notes',
  ];

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
    foreach (self::JSON as $column) {
      $row[$column] = json_decode((string) $row[$column], TRUE) ?: [];
    }
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
   *   Column values. The JSON columns may be arrays.
   */
  public function save(string $entity_type_id, string $uuid, array $fields): void {
    foreach (self::JSON as $column) {
      if (isset($fields[$column]) && is_array($fields[$column])) {
        $fields[$column] = json_encode($fields[$column], \JSON_THROW_ON_ERROR);
      }
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

  /**
   * Marks items as found on the origin by a reconciliation run.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string[] $uuids
   *   UUIDs.
   * @param int $run
   *   The run.
   */
  public function markReconciled(string $entity_type_id, array $uuids, int $run): void {
    foreach (array_chunk($uuids, 500) as $chunk) {
      $this->database->update(self::TABLE)
        ->fields(['last_reconciled' => $run])
        ->condition('entity_type', $entity_type_id)
        ->condition('uuid', $chunk, 'IN')
        ->execute();
    }
  }

  /**
   * Items of a bundle a reconciliation run did not find on the origin.
   *
   * @return string[]
   *   UUIDs, at most $limit.
   */
  public function notReconciled(string $entity_type_id, string $bundle, int $run, int $limit): array {
    $query = $this->database->select(self::TABLE, 's')
      ->fields('s', ['uuid'])
      ->condition('entity_type', $entity_type_id)
      ->condition('bundle', $bundle)
      ->orderBy('uuid')
      ->range(0, $limit);
    $query->condition($query->orConditionGroup()
      ->isNull('last_reconciled')
      ->condition('last_reconciled', $run, '<>'));
    return array_map('strval', $query->execute()->fetchCol());
  }

}
