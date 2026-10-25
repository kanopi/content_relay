<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\MappingException;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Converts dates between formats.
 *
 * Works on scalars or on items' "value". Configuration: from_format and
 * to_format, PHP date formats ("U" for timestamps, "Y-m-d\TH:i:s" for
 * datetime fields); timezone, the timezone to read and write in (default
 * UTC). A value that does not match from_format refuses the item.
 */
#[ContentRelayTransform(
  id: 'date_format',
  label: new TranslatableMarkup('Date format'),
  description: new TranslatableMarkup('Convert a date from one format to another.'),
)]
class DateFormat extends TransformBase {

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    $timezone = new \DateTimeZone((string) ($this->configuration['timezone'] ?? 'UTC'));
    $from = (string) $this->configuration['from_format'];
    $to = (string) $this->configuration['to_format'];
    return self::eachValue($values, function (mixed $value) use ($from, $to, $timezone, $context): mixed {
      if ($value === NULL || $value === '') {
        return $value;
      }
      $date = \DateTimeImmutable::createFromFormat('!' . $from, (string) $value, $timezone);
      if ($date === FALSE) {
        throw new MappingException(sprintf('"%s" for %s is not a date in format %s.', $value, $context->targetField, $from));
      }
      $formatted = $date->setTimezone($timezone)->format($to);
      return $to === 'U' ? (int) $formatted : $formatted;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(): array {
    return empty($this->configuration['from_format']) || empty($this->configuration['to_format']) ? ['A date format needs "from_format" and "to_format".'] : [];
  }

}
