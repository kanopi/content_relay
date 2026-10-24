<?php

declare(strict_types=1);

namespace Drupal\content_relay\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares an entity normalizer plugin.
 *
 * Plugins live in a module's src/Plugin/ContentRelay/Normalizer directory. For
 * each entity type the applicable plugin with the highest weight is used, so a
 * module can override the generic normalizer, or another module's plugin, for
 * the types it knows better.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ContentRelayNormalizer extends Plugin {

  /**
   * Constructs a ContentRelayNormalizer attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   A short human-readable name.
   * @param string[] $entity_types
   *   Entity type IDs this plugin handles, or ['*'] for any content entity
   *   type with a UUID. The plugin's applies() method can still decline.
   * @param int $weight
   *   Higher wins when several plugins apply. The generic normalizer is -100.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly array $entity_types = [],
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
