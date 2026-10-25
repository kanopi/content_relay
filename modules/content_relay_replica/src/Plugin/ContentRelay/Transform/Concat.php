<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Joins the values with values of other source fields into one string.
 *
 * Configuration: sources, more source fields or properties to append
 * ("field_subtitle.value"); delimiter, put between parts (default a space).
 * Empty parts are skipped. Items contribute their "value".
 */
#[ContentRelayTransform(
  id: 'concat',
  label: new TranslatableMarkup('Concatenate'),
  description: new TranslatableMarkup('Join values of several fields.'),
)]
class Concat extends TransformBase {

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    $parts = $values;
    foreach ((array) ($this->configuration['sources'] ?? []) as $path) {
      $parts = [...$parts, ...$context->sourceValues((string) $path)];
    }
    $strings = [];
    foreach ($parts as $part) {
      $part = is_array($part) ? ($part['value'] ?? NULL) : $part;
      if (is_scalar($part) && (string) $part !== '') {
        $strings[] = (string) $part;
      }
    }
    return $strings === [] ? [] : [implode((string) ($this->configuration['delimiter'] ?? ' '), $strings)];
  }

}
