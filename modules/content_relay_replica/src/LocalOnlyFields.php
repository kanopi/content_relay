<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Fields this site keeps for itself, per bundle.
 *
 * Sync never writes a local-only field, and leaves it out when hashing the
 * local copy, so editing one here is not a local edit: it neither causes a
 * conflict nor puts the item out of sync.
 *
 * They are set per bundle in the replica settings, and per mapping for the
 * bundle a mapping fills.
 */
class LocalOnlyFields {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The local-only fields of a bundle here, from settings and mappings.
   *
   * @return string[]
   *   Field names.
   */
  public function forBundle(string $entity_type_id, string $bundle): array {
    $fields = $this->configured($entity_type_id, $bundle);
    $mappings = $this->entityTypeManager->getStorage('content_relay_mapping')->loadByProperties([
      'target_entity_type' => $entity_type_id,
      'target_bundle' => $bundle,
      'status' => TRUE,
    ]);
    foreach ($mappings as $mapping) {
      assert($mapping instanceof ContentRelayMapping);
      $fields = [...$fields, ...$mapping->getLocalOnlyFields()];
    }
    // With a target state of its own, moderation state is this site's, and
    // so is the published flag it sets.
    if ((string) ($this->configFactory->get('content_relay_replica.settings')->get('moderation.target_state') ?? '') !== '') {
      $fields[] = 'moderation_state';
      $published = $this->entityTypeManager->getDefinition($entity_type_id, FALSE)?->getKey('published');
      if (is_string($published) && $published !== '') {
        $fields[] = $published;
      }
    }
    $fields = array_values(array_unique($fields));
    sort($fields);
    return $fields;
  }

  /**
   * The local-only fields of a bundle set in the replica settings.
   *
   * @return string[]
   *   Field names.
   */
  public function configured(string $entity_type_id, string $bundle): array {
    foreach ($this->configFactory->get('content_relay_replica.settings')->get('local_only') ?? [] as $entry) {
      if (($entry['entity_type'] ?? NULL) === $entity_type_id && ($entry['bundle'] ?? NULL) === $bundle) {
        return array_values(array_map('strval', $entry['fields'] ?? []));
      }
    }
    return [];
  }

}
