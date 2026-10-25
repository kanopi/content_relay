<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Converts text formats between the sites' names for them.
 *
 * Works on formatted text items, or on format names when the "format"
 * property is mapped on its own. Configuration: map, origin format => replica
 * format; default, the replica format for formats not in the map (optional;
 * without it they are kept).
 */
#[ContentRelayTransform(
  id: 'text_format',
  label: new TranslatableMarkup('Text format'),
  description: new TranslatableMarkup("Use the replica's text formats."),
)]
class TextFormat extends TransformBase {

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    $map = (array) ($this->configuration['map'] ?? []);
    $default = $this->configuration['default'] ?? NULL;
    $convert = fn (mixed $format): mixed => is_string($format) ? ($map[$format] ?? $default ?? $format) : $format;
    return array_map(function (mixed $value) use ($convert): mixed {
      // Whole items, or the format property mapped on its own.
      if (is_array($value)) {
        if (array_key_exists('format', $value)) {
          $value['format'] = $convert($value['format']);
        }
        return $value;
      }
      return $convert($value);
    }, array_values($values));
  }

}
