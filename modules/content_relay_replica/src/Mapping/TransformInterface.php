<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Changes values on their way from an origin field to a replica field.
 *
 * The value passed along a mapping's transforms is always a list: the items
 * of a field (each a map of properties, in the portable form the origin
 * sends) when a whole field is mapped, or the values of one property of each
 * item when a property is mapped ("body.value"). Each transform receives the
 * list and returns the new list. What the last one returns is written to the
 * target as it is, so a transform that turns portable references into local
 * IDs (taxonomy_lookup) must come last or be followed by ones that keep them.
 */
interface TransformInterface extends PluginInspectionInterface {

  /**
   * Transforms a list of values.
   *
   * @param array $values
   *   The values, a list.
   * @param \Drupal\content_relay_replica\Mapping\TransformContext $context
   *   Where the values come from, for transforms that look further.
   *
   * @return array
   *   The new values, a list.
   *
   * @throws \Drupal\content_relay_replica\Mapping\MappingException
   *   When the values cannot be transformed and the item must not be saved.
   */
  public function transform(array $values, TransformContext $context): array;

  /**
   * Problems with the plugin's configuration, for the mapping form.
   *
   * @return string[]
   *   Messages; empty when it is valid.
   */
  public function validateConfiguration(): array;

}
