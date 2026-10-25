<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;

/**
 * The latest revision of an item, which is what sync sends and compares.
 *
 * With Content Moderation, the latest revision can be a draft on top of the
 * published one. Sending it lets a replica hold the same draft, with the
 * same moderation state per translation; its moderation decides which
 * revision is live, as on the origin. Without forward revisions, the latest
 * revision is the default one.
 */
final class LatestRevision {

  /**
   * The latest revision of an entity, or the entity itself.
   */
  public static function of(ContentEntityInterface $entity, EntityTypeManagerInterface $entity_type_manager): ContentEntityInterface {
    if ($entity->isNew() || !$entity->getEntityType()->isRevisionable()) {
      return $entity;
    }
    $storage = $entity_type_manager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof RevisionableStorageInterface) {
      return $entity;
    }
    $latest = $storage->getLatestRevisionId($entity->id());
    if ($latest === NULL || (string) $latest === (string) $entity->getRevisionId()) {
      return $entity;
    }
    $revision = $storage->loadRevision($latest);
    return $revision instanceof ContentEntityInterface ? $revision : $entity;
  }

}
