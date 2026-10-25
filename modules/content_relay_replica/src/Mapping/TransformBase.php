<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

use Drupal\Core\Plugin\PluginBase;

/**
 * Defaults for transforms.
 */
abstract class TransformBase extends PluginBase implements TransformInterface {

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(): array {
    return [];
  }

  /**
   * Applies a function to each value, or to its "value" property for items.
   */
  protected static function eachValue(array $values, callable $change): array {
    return array_map(function (mixed $value) use ($change): mixed {
      if (is_array($value) && array_key_exists('value', $value)) {
        $value['value'] = $change($value['value']);
        return $value;
      }
      return $change($value);
    }, array_values($values));
  }

}
