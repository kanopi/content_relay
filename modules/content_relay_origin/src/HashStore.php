<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Hash\RevisionHashes;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Keeps the canonical hash of every synced revision.
 *
 * Hashes are computed when an entity is saved, from the full snapshot with
 * every translation. Replicas that filter languages compare per-translation
 * hashes, which do not depend on which other translations were included.
 *
 * Entity types without revisions get a version counter instead of a revision
 * ID. It goes up by one whenever a save changes the hash, so it orders
 * versions the same way a revision ID would.
 */
class HashStore {

  public const TABLE = 'content_relay_revision_hash';

  public function __construct(
    protected Connection $database,
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
    protected TimeInterface $time,
  ) {}

  /**
   * Computes and stores the hashes of an entity's current revision.
   *
   * @return array{revision: int, hashes: \Drupal\content_relay\Hash\RevisionHashes}
   *   The revision ID or version, and the hashes.
   */
  public function record(ContentEntityInterface $entity): array {
    $hashes = $this->hasher->hash($this->serializer->normalize($entity));
    if ($entity->getEntityType()->isRevisionable()) {
      $revision = (int) $entity->getRevisionId();
    }
    else {
      $latest = $this->latest($entity->getEntityTypeId(), $entity->uuid());
      $revision = $latest === NULL ? 1 : ($latest['hashes']->entity === $hashes->entity ? $latest['revision'] : $latest['revision'] + 1);
    }
    $this->database->merge(self::TABLE)
      ->keys([
        'entity_type' => $entity->getEntityTypeId(),
        'uuid' => $entity->uuid(),
        'revision' => $revision,
        'algorithm' => $hashes->algorithmVersion,
      ])
      ->fields([
        'entity_hash' => $hashes->entity,
        'translation_hashes' => json_encode($hashes->translations, \JSON_THROW_ON_ERROR),
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();
    return ['revision' => $revision, 'hashes' => $hashes];
  }

  /**
   * The latest stored revision and hashes of an entity.
   *
   * @return array{revision: int, hashes: \Drupal\content_relay\Hash\RevisionHashes}|null
   *   NULL when nothing is stored.
   */
  public function latest(string $entity_type_id, string $uuid, int $algorithm = RevisionHasher::CURRENT_VERSION): ?array {
    $row = $this->database->select(self::TABLE, 'h')
      ->fields('h')
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->condition('algorithm', $algorithm)
      ->orderBy('revision', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    return [
      'revision' => (int) $row['revision'],
      'hashes' => new RevisionHashes((int) $row['algorithm'], $row['entity_hash'], json_decode($row['translation_hashes'], TRUE)),
    ];
  }

  /**
   * Removes the hashes of a deleted entity.
   */
  public function forget(string $entity_type_id, string $uuid): void {
    $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute();
  }

}
