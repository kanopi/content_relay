<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Passes each value through a PHP function or static method.
 *
 * Configuration: callable, a function name ("strtoupper") or a static method
 * ("Drupal\my_module\Values::clean"). Items contribute their "value". As with
 * Migrate's callback, whoever can change mappings can call any function, so
 * changing mappings needs a restricted permission.
 */
#[ContentRelayTransform(
  id: 'callback',
  label: new TranslatableMarkup('Callback'),
  description: new TranslatableMarkup('Pass each value through a function.'),
)]
class Callback extends TransformBase {

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    $callable = (string) $this->configuration['callable'];
    return self::eachValue($values, fn (mixed $value): mixed => $callable($value));
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(): array {
    $callable = $this->configuration['callable'] ?? NULL;
    return is_string($callable) && is_callable($callable) ? [] : ['A callback needs "callable", the name of a function or static method.'];
  }

}
