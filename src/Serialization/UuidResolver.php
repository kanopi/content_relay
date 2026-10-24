<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;

/**
 * Translates between local entity IDs and UUIDs, in batches.
 *
 * Every reference crosses sites as a UUID and is resolved to a local ID on
 * arrival, so this runs for every reference in every snapshot. It never loads
 * entities: for SQL storage it reads the ID and UUID columns of the base table
 * directly, one query per entity type per batch, and remembers the answers for
 * the rest of the request. Other storages fall back to an entity query.
 *
 * Callers that are about to look up many IDs should prime() them first, so a
 * whole snapshot, or a whole batch of snapshots, costs one query per type.
 */
class UuidResolver {

  /**
   * Known UUIDs, by entity type and local ID. FALSE when the ID does not exist.
   *
   * @var array<string, array<int|string, string|false>>
   */
  protected array $uuids = [];

  /**
   * Known local IDs, by entity type and UUID. FALSE when the UUID is unknown.
   *
   * @var array<string, array<string, int|string|false>>
   */
  protected array $ids = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Looks up the UUIDs of local IDs not already known.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param array<int|string> $ids
   *   Local IDs.
   */
  public function primeIds(string $entity_type_id, array $ids): void {
    $missing = array_values(array_filter(array_unique($ids), fn ($id) => !array_key_exists($id, $this->uuids[$entity_type_id] ?? [])));
    if ($missing === []) {
      return;
    }
    foreach (array_chunk($missing, 500) as $chunk) {
      $found = $this->query($entity_type_id, 'id', $chunk);
      foreach ($chunk as $id) {
        $this->uuids[$entity_type_id][$id] = $found[$id] ?? FALSE;
      }
      foreach ($found as $id => $uuid) {
        $this->ids[$entity_type_id][$uuid] = $id;
      }
    }
  }

  /**
   * Looks up the local IDs of UUIDs not already known.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $uuids
   *   UUIDs.
   */
  public function primeUuids(string $entity_type_id, array $uuids): void {
    $missing = array_values(array_filter(array_unique($uuids), fn ($uuid) => !array_key_exists($uuid, $this->ids[$entity_type_id] ?? [])));
    if ($missing === []) {
      return;
    }
    foreach (array_chunk($missing, 500) as $chunk) {
      $found = array_flip($this->query($entity_type_id, 'uuid', $chunk));
      foreach ($chunk as $uuid) {
        $this->ids[$entity_type_id][$uuid] = $found[$uuid] ?? FALSE;
      }
      foreach ($found as $uuid => $id) {
        $this->uuids[$entity_type_id][$id] = $uuid;
      }
    }
  }

  /**
   * The UUID of a local ID, or NULL when no such entity exists.
   */
  public function toUuid(string $entity_type_id, int|string $id): ?string {
    $this->primeIds($entity_type_id, [$id]);
    return $this->uuids[$entity_type_id][$id] ?: NULL;
  }

  /**
   * The local ID of a UUID, or NULL when no such entity exists here.
   */
  public function toId(string $entity_type_id, string $uuid): int|string|null {
    $this->primeUuids($entity_type_id, [$uuid]);
    return $this->ids[$entity_type_id][$uuid] ?: NULL;
  }

  /**
   * Forgets what is known, after entities were created or deleted.
   *
   * @param string|null $entity_type_id
   *   One entity type, or NULL for all of them.
   */
  public function reset(?string $entity_type_id = NULL): void {
    if ($entity_type_id === NULL) {
      $this->uuids = $this->ids = [];
      return;
    }
    unset($this->uuids[$entity_type_id], $this->ids[$entity_type_id]);
  }

  /**
   * Reads ID => UUID pairs matching a list of IDs or UUIDs.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $by
   *   Either 'id' or 'uuid'.
   * @param array $values
   *   The values to match.
   *
   * @return array<int|string, string>
   *   UUIDs keyed by local ID.
   */
  protected function query(string $entity_type_id, string $by, array $values): array {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $id_key = $definition->getKey('id');
    $uuid_key = $definition->getKey('uuid');
    if (!$id_key || !$uuid_key) {
      throw new \InvalidArgumentException(sprintf('Entity type "%s" has no UUID key and cannot be synced.', $entity_type_id));
    }
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $column = $by === 'id' ? $id_key : $uuid_key;

    if ($storage instanceof SqlEntityStorageInterface && ($table = $definition->getBaseTable())) {
      return $this->database->select($table, 'b')
        ->fields('b', [$id_key, $uuid_key])
        ->condition('b.' . $column, $values, 'IN')
        ->execute()
        ->fetchAllKeyed();
    }

    // Not SQL: the entity query finds the IDs, then the entities have to be
    // loaded for their UUIDs. Slower, but no storage is left unsupported.
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition($column, $values, 'IN')->execute();
    $pairs = [];
    foreach ($storage->loadMultiple($ids) as $id => $entity) {
      $pairs[$id] = $entity->uuid();
    }
    return $pairs;
  }

}
