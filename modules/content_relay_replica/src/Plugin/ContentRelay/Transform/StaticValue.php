<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Always the same value, whatever the origin sent.
 *
 * Configuration: value, a scalar or an item (map of properties).
 */
#[ContentRelayTransform(
  id: 'static_value',
  label: new TranslatableMarkup('Static value'),
  description: new TranslatableMarkup('Always this value.'),
)]
class StaticValue extends TransformBase {

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    return [$this->configuration['value'] ?? NULL];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(): array {
    return array_key_exists('value', $this->configuration) ? [] : ['A static value needs "value".'];
  }

}
