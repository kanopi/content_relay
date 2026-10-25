<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a transform plugin for mapped imports.
 *
 * Transforms change values on their way from an origin field to a replica
 * field, like Migrate process plugins. They live in a module's
 * src/Plugin/ContentRelay/Transform directory.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ContentRelayTransform extends Plugin {

  /**
   * Constructs a ContentRelayTransform attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   A short human-readable name.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   What it does and how it is configured.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
