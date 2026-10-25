<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Computes hashes for content saved before this origin kept them.
 *
 * Hashes are normally stored on save. Content that existed before the module
 * was enabled, or before a bundle was published, has none until it is next
 * saved; the hash listing computes missing ones on demand, but a backfill
 * does it ahead of time, for every published bundle.
 */
class HashBackfill {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SyncScope $scope,
    protected HashStore $hashStore,
  ) {}

  /**
   * Hashes every published item whose current version has no stored hash.
   *
   * @param bool $all
   *   TRUE to recompute every item, stored or not.
   * @param callable(string, string, int): void|null $progress
   *   Told the entity type, bundle and number done after each chunk.
   *
   * @return int
   *   The number of items hashed.
   */
  public function run(bool $all = FALSE, ?callable $progress = NULL): int {
    $hashed = 0;
    foreach ($this->scope->getPublishedBundles() as $entity_type_id => $bundles) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if ($definition === NULL) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach ($bundles as $bundle) {
        $last = 0;
        do {
          $query = $storage->getQuery()->accessCheck(FALSE)->sort((string) $definition->getKey('id'))->range(0, 50);
          if ($definition->hasKey('bundle')) {
            $query->condition((string) $definition->getKey('bundle'), $bundle);
          }
          $ids = $query->condition((string) $definition->getKey('id'), $last, '>')->execute();
          foreach ($storage->loadMultiple($ids) as $entity) {
            if ($entity instanceof ContentEntityInterface && ($all || $this->isMissing($entity))) {
              $this->hashStore->record($entity);
              $hashed++;
            }
          }
          $last = $ids === [] ? $last : end($ids);
          $storage->resetCache($ids);
          if ($progress !== NULL) {
            $progress($entity_type_id, $bundle, $hashed);
          }
        } while (count($ids) === 50);
      }
    }
    return $hashed;
  }

  /**
   * Whether an item's current version has no stored hash.
   */
  protected function isMissing(ContentEntityInterface $entity): bool {
    $latest = $this->hashStore->latest($entity->getEntityTypeId(), (string) $entity->uuid());
    if ($latest === NULL) {
      return TRUE;
    }
    return $entity->getEntityType()->isRevisionable() && $latest['revision'] !== (int) $entity->getRevisionId();
  }

}
