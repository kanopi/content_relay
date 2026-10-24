<?php

declare(strict_types=1);

namespace Drupal\content_relay\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a field normalizer plugin.
 *
 * Field normalizers handle a kind of field wherever it appears, on any entity
 * type: references, embedded entities, links, moderation state. They live in
 * a module's src/Plugin/ContentRelay/FieldNormalizer directory. For each field
 * the applicable plugin with the highest weight is used.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ContentRelayFieldNormalizer extends Plugin {

  /**
   * Constructs a ContentRelayFieldNormalizer attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   A short human-readable name.
   * @param int $weight
   *   Higher wins when several plugins apply to one field.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {}

}
