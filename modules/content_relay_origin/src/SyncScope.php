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
   * Every bundle the origin publishes, by entity type.
   *
   * @return array<string, string[]>
   *   Bundles.
   */
  public function getPublishedBundles(): array {
    $bundles = $this->configFactory->get('content_relay_origin.settings')->get('bundles') ?? [];
    return array_filter(array_map(fn ($list) => array_values((array) $list), $bundles));
  }

  /**
   * Whether a bundle is configured for sync.
   */
  public function isBundleSynced(string $entity_type_id, string $bundle): bool {
    $bundles = $this->configFactory->get('content_relay_origin.settings')->get('bundles') ?? [];
    return in_array($bundle, $bundles[$entity_type_id] ?? [], TRUE);
  }

}
