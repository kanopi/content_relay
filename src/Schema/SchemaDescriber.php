<?php

declare(strict_types=1);

namespace Drupal\content_relay\Schema;

use Drupal\content_relay\Serialization\NormalizerManager;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Describes the synced fields of bundles, for comparing two sites.
 *
 * The origin publishes this through its schema endpoint; a replica describes
 * its own bundles the same way to check whether it can take content as it is,
 * and to offer fields in the mapping UI. Only what decides whether values fit
 * is described: field type, cardinality, translatability, whether required,
 * the referenced entity type, and the item properties.
 */
class SchemaDescriber {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected NormalizerManager $normalizerManager,
  ) {}

  /**
   * Describes one bundle, or NULL when it does not exist here.
   *
   * @return array{label: string, translatable: bool, fields: array<string, array>}|null
   *   The bundle's label, whether the entity type is translatable, and its
   *   synced fields by name.
   */
  public function describe(string $entity_type_id, string $bundle): ?array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    $bundles = $entity_type instanceof ContentEntityTypeInterface ? $this->bundleInfo->getBundleInfo($entity_type_id) : [];
    if (!isset($bundles[$bundle])) {
      return NULL;
    }
    $fields = [];
    foreach ($this->normalizerManager->getNormalizer($entity_type_id)->getSyncedFieldDefinitions($entity_type, $bundle) as $name => $definition) {
      $fields[$name] = self::describeField($definition);
    }
    return [
      'label' => (string) ($bundles[$bundle]['label'] ?? $bundle),
      'translatable' => $entity_type->isTranslatable(),
      'fields' => $fields,
    ];
  }

  /**
   * Describes several bundles.
   *
   * @param array<string, string[]> $bundles
   *   Bundles by entity type.
   *
   * @return array<string, array<string, array>>
   *   Descriptions by entity type and bundle; bundles that do not exist are
   *   left out.
   */
  public function describeMany(array $bundles): array {
    $described = [];
    foreach ($bundles as $entity_type_id => $list) {
      foreach ($list as $bundle) {
        if (($description = $this->describe($entity_type_id, $bundle)) !== NULL) {
          $described[$entity_type_id][$bundle] = $description;
        }
      }
    }
    return $described;
  }

  /**
   * A fingerprint of a bundle description, to notice changes.
   */
  public static function fingerprint(array $description): string {
    $fields = $description['fields'] ?? [];
    ksort($fields);
    return hash('sha256', serialize($fields));
  }

  /**
   * Describes one field.
   *
   * @return array{type: string, cardinality: int, translatable: bool, required: bool, target_type: string|null, properties: string[]}
   *   The description.
   */
  public static function describeField(FieldDefinitionInterface $definition): array {
    $storage = $definition->getFieldStorageDefinition();
    $properties = array_keys($storage->getPropertyDefinitions());
    sort($properties);
    return [
      'type' => $definition->getType(),
      'cardinality' => $storage->getCardinality(),
      'translatable' => $definition->isTranslatable(),
      'required' => $definition->isRequired(),
      'target_type' => is_string($definition->getSetting('target_type')) ? $definition->getSetting('target_type') : NULL,
      'properties' => $properties,
    ];
  }

}
