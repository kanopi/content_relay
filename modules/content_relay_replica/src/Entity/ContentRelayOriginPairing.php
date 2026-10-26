<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Entity;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * How this site receives from one origin.
 *
 * Each origin this site is paired with has one: its receiving settings, such
 * as conflict handling, languages, local-only fields and files, so they are
 * exported and deployed like any configuration. Anything a pairing leaves out
 * follows the site-wide settings (content_relay_replica.settings).
 *
 * Who the origin is, its keys, the contract and the stream position are live
 * state, kept in the database under the pairing's ID; key rotation and
 * received events must not change configuration.
 */
#[ConfigEntityType(
  id: 'content_relay_origin_pairing',
  label: new TranslatableMarkup('Content Relay origin pairing'),
  label_collection: new TranslatableMarkup('Content Relay origin pairings'),
  label_singular: new TranslatableMarkup('origin pairing'),
  label_plural: new TranslatableMarkup('origin pairings'),
  config_prefix: 'origin_pairing',
  entity_keys: ['id' => 'id', 'label' => 'label'],
  admin_permission: 'administer content relay replica',
  config_export: ['id', 'label', 'settings'],
)]
class ContentRelayOriginPairing extends ConfigEntityBase {

  /**
   * The pairing created for a site's first origin.
   */
  public const DEFAULT = 'default';

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The label.
   */
  protected string $label = '';

  /**
   * Receiving settings, as content_relay_replica.settings has them.
   */
  protected array $settings = [];

  /**
   * A setting, by dotted path, or NULL when the pairing does not set it.
   */
  public function getSetting(string $path): mixed {
    return NestedArray::getValue($this->settings, explode('.', $path));
  }

  /**
   * Sets a setting, by dotted path.
   */
  public function setSetting(string $path, mixed $value): static {
    NestedArray::setValue($this->settings, explode('.', $path), $value);
    return $this;
  }

  /**
   * All the pairing's settings.
   */
  public function getSettings(): array {
    return $this->settings;
  }

}
