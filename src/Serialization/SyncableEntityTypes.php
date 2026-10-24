<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Which entity types and bundles can be offered for sync at all.
 *
 * A type qualifies when it is a content entity type with a UUID key and is not
 * embedded in another entity's snapshot. Configuration forms build their
 * options from this, so a type without a UUID never appears as a choice.
 */
class SyncableEntityTypes {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected NormalizerManager $normalizerManager,
  ) {}

  /**
   * Entity types that can be enabled for sync, sorted by ID.
   *
   * @param bool $include_embedded
   *   Also list types that only travel inside a parent, such as paragraphs.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityTypeInterface>
   *   Entity types keyed by ID.
   */
  public function getEntityTypes(bool $include_embedded = FALSE): array {
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasKey('uuid')) {
        continue;
      }
      try {
        $normalizer = $this->normalizerManager->getNormalizer($id);
      }
      catch (\InvalidArgumentException) {
        continue;
      }
      if ($include_embedded || !$normalizer->isEmbedded($definition)) {
        $types[$id] = $definition;
      }
    }
    ksort($types);
    return $types;
  }

  /**
   * Whether an entity type can be synced.
   */
  public function isSyncable(string $entity_type_id): bool {
    return isset($this->getEntityTypes(TRUE)[$entity_type_id]);
  }

  /**
   * Bundle labels of a syncable entity type, keyed by bundle.
   *
   * @return array<string, string>
   *   Labels.
   */
  public function getBundles(string $entity_type_id): array {
    $bundles = [];
    foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
      $bundles[$bundle] = (string) ($info['label'] ?? $bundle);
    }
    return $bundles;
  }

}
