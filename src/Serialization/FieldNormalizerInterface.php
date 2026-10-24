<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * Turns the items of one kind of field into portable values and back.
 *
 * Field normalizers are what make sync work for any entity type: anything
 * site-specific inside a field value (an entity ID, a path containing one, an
 * embedded entity) is handled by the field normalizer for that kind of field,
 * whatever entity it is on. Entity normalizers only deal with what is truly
 * particular to one entity type.
 *
 * Extend FieldNormalizerBase.
 */
interface FieldNormalizerInterface extends PluginInspectionInterface {

  /**
   * Whether this plugin handles a field.
   */
  public function applies(FieldDefinitionInterface $definition): bool;

  /**
   * Whether the field is synced even though it is computed.
   *
   * Computed fields are normally derived on each site and left out. Some are
   * computed from data stored elsewhere and are meaningful to sync, such as
   * moderation state.
   */
  public function syncsComputed(): bool;

  /**
   * Normalizes one item.
   *
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   The item.
   * @param array $values
   *   Its stored properties, already cast to their types, NULLs left out.
   * @param \Drupal\content_relay\Serialization\NormalizationContext $context
   *   Options and collected references.
   * @param \Drupal\content_relay\Serialization\EntitySerializer $serializer
   *   The serializer, for embedded entities and UUID lookups.
   *
   * @return array|null
   *   The portable item, or NULL to leave it out.
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array;

  /**
   * Turns one portable item back into a value the field accepts.
   *
   * @return array|null
   *   The local item, or NULL to leave it out.
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array;

  /**
   * Local entity IDs an item refers to, so they can be looked up in bulk.
   *
   * @return array<string, array<int|string>>
   *   IDs by entity type.
   */
  public function referencedIds(FieldItemInterface $item): array;

  /**
   * UUIDs a portable item refers to, so they can be looked up in bulk.
   *
   * @return array<string, string[]>
   *   UUIDs by entity type.
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array;

}
