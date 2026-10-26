<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Finds the mapping for an origin bundle.
 */
class MappingRepository {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ActivePairing $active,
  ) {}

  /**
   * The enabled mapping for an origin bundle, or NULL to import as it is.
   *
   * A mapping for the current origin comes before one for every origin.
   */
  public function forSource(string $entity_type_id, string $bundle): ?ContentRelayMapping {
    if (!$this->entityTypeManager->hasDefinition('content_relay_mapping')) {
      return NULL;
    }
    try {
      $pairing = $this->active->id();
    }
    catch (\LogicException) {
      $pairing = '';
    }
    $storage = $this->entityTypeManager->getStorage('content_relay_mapping');
    foreach (array_unique([$pairing, '']) as $for) {
      $mapping = $storage->load(ContentRelayMapping::idFor($entity_type_id, $bundle, $for));
      if ($mapping instanceof ContentRelayMapping) {
        return $mapping->status() ? $mapping : NULL;
      }
    }
    return NULL;
  }

}
