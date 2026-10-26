<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Entity;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * How this origin sends to one replica.
 *
 * Each paired replica has one: the bundles published to it, delivery,
 * encryption, read limits, file links and how items marked not syncable are
 * handled, so they are exported and deployed like any
 * configuration. Anything a pairing leaves out follows the site-wide
 * settings; a new pairing follows them until it is given its own.
 *
 * The replica's keys, contract and event log are live state, in the
 * database under its site ID, kept in replica_id here.
 */
#[ConfigEntityType(
  id: 'content_relay_replica_pairing',
  label: new TranslatableMarkup('Content Relay replica pairing'),
  label_collection: new TranslatableMarkup('Content Relay replica pairings'),
  label_singular: new TranslatableMarkup('replica pairing'),
  label_plural: new TranslatableMarkup('replica pairings'),
  config_prefix: 'replica_pairing',
  entity_keys: ['id' => 'id', 'label' => 'label'],
  admin_permission: 'administer content relay replicas',
  config_export: ['id', 'label', 'replica_id', 'settings'],
)]
class ContentRelayReplicaPairing extends ConfigEntityBase {

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The label.
   */
  protected string $label = '';

  /**
   * The replica's site ID.
   */
  protected string $replica_id = '';

  /**
   * Sending settings, as content_relay_origin.settings has them.
   */
  protected array $settings = [];

  /**
   * The replica's site ID.
   */
  public function getReplicaId(): string {
    return $this->replica_id;
  }

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
