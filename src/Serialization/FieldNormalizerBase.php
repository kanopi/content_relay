<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * Defaults for field normalizers: values pass through unchanged.
 */
abstract class FieldNormalizerBase extends PluginBase implements FieldNormalizerInterface {

  /**
   * {@inheritdoc}
   */
  public function syncsComputed(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedIds(FieldItemInterface $item): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    return [];
  }

  /**
   * Whether a field's item class is, or extends, a class that may not exist.
   *
   * Lets plugins recognize contrib field types without depending on them.
   */
  protected static function itemIs(FieldDefinitionInterface $definition, string $class): bool {
    return is_a($definition->getItemDefinition()->getClass(), $class, TRUE);
  }

}
