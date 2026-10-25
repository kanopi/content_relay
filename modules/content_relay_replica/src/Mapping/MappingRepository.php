<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Finds the mapping for an origin bundle.
 */
class MappingRepository {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The enabled mapping for an origin bundle, or NULL to import as it is.
   */
  public function forSource(string $entity_type_id, string $bundle): ?ContentRelayMapping {
    if (!$this->entityTypeManager->hasDefinition('content_relay_mapping')) {
      return NULL;
    }
    $mapping = $this->entityTypeManager->getStorage('content_relay_mapping')->load(ContentRelayMapping::idFor($entity_type_id, $bundle));
    return $mapping instanceof ContentRelayMapping && $mapping->status() ? $mapping : NULL;
  }

}
