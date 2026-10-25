<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A value to use when the origin sent none.
 *
 * Configuration: value, a scalar or an item (map of properties).
 */
#[ContentRelayTransform(
  id: 'default_value',
  label: new TranslatableMarkup('Default value'),
  description: new TranslatableMarkup('This value when there is none.'),
)]
class DefaultValue extends TransformBase {

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    $values = array_values(array_filter($values, fn ($value) => $value !== NULL && $value !== '' && $value !== []));
    return $values === [] ? [$this->configuration['value'] ?? NULL] : $values;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(): array {
    return array_key_exists('value', $this->configuration) ? [] : ['A default value needs "value".'];
  }

}
