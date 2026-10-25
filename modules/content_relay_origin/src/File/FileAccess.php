<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\File;

use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\HashStore;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\file\FileInterface;

/**
 * Which files a replica may download.
 *
 * A file is served to a replica only while some item that uses it (in a
 * field or linked in text, as recorded with the item's hash) is published,
 * in the replica's contract and not marked not syncable for it. A file used
 * only by media is served while the media item qualifies, recursively, so
 * a file embedded through media follows the media.
 */
class FileAccess {

  public function __construct(
    protected HashStore $hashStore,
    protected SyncScope $scope,
    protected ContractManager $contracts,
    protected NotSyncableFlags $flags,
    protected ReplicaRegistry $registry,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * Whether a replica may have a file.
   */
  public function allows(string $replica_id, FileInterface $file): bool {
    $replica = $this->registry->get($replica_id);
    return $replica !== NULL && $replica->receivesEvents() && $this->usedByAllowed($replica_id, (string) $file->uuid(), []);
  }

  /**
   * Whether an item the replica may have uses a file.
   *
   * @param string $replica_id
   *   The replica.
   * @param string $file_uuid
   *   The file.
   * @param array<string, true> $seen
   *   Items already checked, against cycles.
   */
  protected function usedByAllowed(string $replica_id, string $file_uuid, array $seen): bool {
    foreach ($this->hashStore->itemsUsingFile($file_uuid) as $item) {
      $key = $item['entity_type'] . ':' . $item['uuid'];
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
      $entity = $this->entityRepository->loadEntityByUuid($item['entity_type'], $item['uuid']);
      if ($entity instanceof ContentEntityInterface
        && $this->scope->applies($entity)
        && $this->contracts->coversBundle($replica_id, $entity->getEntityTypeId(), $entity->bundle())
        && !$this->flags->isBlocked($entity->getEntityTypeId(), $item['uuid'], $replica_id)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
