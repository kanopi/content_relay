<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Hash\RevisionHashes;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
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
 *
 * The same normalization records which files the item uses (FILE_REF_TABLE),
 * which decides what files a replica may download, and the links to files
 * that could not be resolved, which the status report flags.
 */
class HashStore {

  public const TABLE = 'content_relay_revision_hash';

  public const FILE_REF_TABLE = 'content_relay_file_ref';

  public function __construct(
    protected Connection $database,
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
    protected TimeInterface $time,
  ) {}

  /**
   * Computes and stores the hashes of an entity's current revision.
   *
   * @return array{revision: int, hashes: \Drupal\content_relay\Hash\RevisionHashes, unresolved: array}
   *   The revision ID or version, the hashes, and unresolved references.
   */
  public function record(ContentEntityInterface $entity): array {
    $context = new NormalizationContext();
    $hashes = $this->hasher->hash($this->serializer->normalize($entity, $context));
    $this->recordFiles($entity->getEntityTypeId(), (string) $entity->uuid(), $context);
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
        'unresolved' => $context->unresolved === [] ? NULL : json_encode($context->unresolved, \JSON_THROW_ON_ERROR),
        'created' => $this->time->getCurrentTime(),
      ])
      ->execute();
    return ['revision' => $revision, 'hashes' => $hashes, 'unresolved' => $context->unresolved];
  }

  /**
   * Replaces the record of the files an item uses.
   */
  protected function recordFiles(string $entity_type_id, string $uuid, NormalizationContext $context): void {
    $this->database->delete(self::FILE_REF_TABLE)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute();
    $insert = $this->database->insert(self::FILE_REF_TABLE)->fields(['entity_type', 'uuid', 'file_uuid']);
    $any = FALSE;
    foreach ($context->references as $reference) {
      if ($reference['entity_type'] === 'file') {
        $insert->values([$entity_type_id, $uuid, $reference['uuid']]);
        $any = TRUE;
      }
    }
    if ($any) {
      $insert->execute();
    }
  }

  /**
   * The items that use a file.
   *
   * @return array<array{entity_type: string, uuid: string}>
   *   Items.
   */
  public function itemsUsingFile(string $file_uuid): array {
    return array_map(fn ($row) => (array) $row, $this->database->select(self::FILE_REF_TABLE, 'r')
      ->fields('r', ['entity_type', 'uuid'])
      ->condition('file_uuid', $file_uuid)
      ->execute()
      ->fetchAll());
  }

  /**
   * The latest stored revision and hashes of an entity.
   *
   * @return array{revision: int, hashes: \Drupal\content_relay\Hash\RevisionHashes, unresolved: array}|null
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
      'unresolved' => $row['unresolved'] === NULL ? [] : (json_decode($row['unresolved'], TRUE) ?: []),
    ];
  }

  /**
   * Removes the hashes of a deleted entity.
   */
  public function forget(string $entity_type_id, string $uuid): void {
    foreach ([self::TABLE, self::FILE_REF_TABLE] as $table) {
      $this->database->delete($table)
        ->condition('entity_type', $entity_type_id)
        ->condition('uuid', $uuid)
        ->execute();
    }
  }

}
