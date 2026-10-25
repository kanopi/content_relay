<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\views\field;

use Drupal\Component\Render\MarkupInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * A JSON list or map column, shown as text.
 *
 * Lists show as "fr, de"; maps as "skipped_languages: fr; ...".
 */
#[ViewsField('content_relay_json_list')]
final class JsonList extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface|string {
    $decoded = json_decode((string) $this->getValue($values), TRUE);
    if (!is_array($decoded) || $decoded === []) {
      return '';
    }
    $flatten = fn ($value) => is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
    $text = array_is_list($decoded)
      ? $flatten($decoded)
      : implode('; ', array_map(fn ($key, $value) => $key . ': ' . $flatten($value), array_keys($decoded), $decoded));
    return $this->sanitizeValue($text);
  }

}
