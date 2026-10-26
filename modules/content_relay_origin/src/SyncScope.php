<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Serialization\NormalizerManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Which entities the origin publishes.
 *
 * Configured per entity type and bundle. Embedded entity types (paragraphs)
 * are never published on their own; they travel inside their parent. Files
 * are not configured either: they are sent with the content that uses them.
 */
class SyncScope {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected NormalizerManager $normalizerManager,
    protected SendingSettings $sending,
  ) {}

  /**
   * Whether changes to an entity produce events.
   */
  public function applies(EntityInterface $entity): bool {
    if (!$entity instanceof ContentEntityInterface || !$this->isBundleSynced($entity->getEntityTypeId(), $entity->bundle())) {
      return FALSE;
    }
    try {
      return !$this->normalizerManager->isEmbedded($entity->getEntityTypeId());
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * Whether an entity is sent with the content that uses it.
   */
  public function followsReferrers(EntityInterface $entity): bool {
    return $entity instanceof ContentEntityInterface && in_array($entity->getEntityTypeId(), SubscriptionContract::FOLLOWING_TYPES, TRUE);
  }

  /**
   * Whether an entity can be sent at all: published, or following content.
   */
  public function canSend(EntityInterface $entity): bool {
    return $this->applies($entity) || $this->followsReferrers($entity);
  }

  /**
   * The bundles the origin publishes, by entity type.
   *
   * @param string|null $replica_id
   *   A replica: the bundles published to it. NULL: every bundle published
   *   to any replica, or by default to new ones.
   *
   * @return array<string, string[]>
   *   Bundles.
   */
  public function getPublishedBundles(?string $replica_id = NULL): array {
    $lists = [$this->sending->get('bundles', $replica_id) ?? []];
    if ($replica_id === NULL) {
      foreach ($this->sending->pairings() as $pairing) {
        $lists[] = $pairing->getSetting('bundles') ?? [];
      }
    }
    $published = [];
    foreach ($lists as $bundles) {
      foreach ((array) $bundles as $entity_type_id => $list) {
        $merged = array_merge($published[$entity_type_id] ?? [], array_values((array) $list));
        $published[$entity_type_id] = array_values(array_unique($merged));
      }
    }
    return array_filter($published);
  }

  /**
   * Whether a bundle is published to any replica.
   */
  public function isBundleSynced(string $entity_type_id, string $bundle): bool {
    return in_array($bundle, $this->getPublishedBundles()[$entity_type_id] ?? [], TRUE);
  }

}
