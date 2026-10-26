<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay_origin\Entity\ContentRelayReplicaPairing;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * How content is sent to a replica: its pairing's settings, or site-wide.
 *
 * Every setting about sending to one replica is read through here. What a
 * pairing leaves out follows the site-wide settings: the origin's, and for
 * encryption the shared content_relay.settings. A new pairing leaves out
 * everything, so it follows the site-wide settings until it is given its own.
 */
class SendingSettings {

  /**
   * Settings a pairing can have.
   *
   * The rest of the site-wide settings are not about one replica.
   */
  public const KEYS = ['bundles', 'delivery', 'read_limit', 'not_syncable', 'files', 'encryption'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TransliterationInterface $transliteration,
  ) {}

  /**
   * A sending setting for a replica.
   *
   * @param string $path
   *   Dotted path, such as "delivery.max_attempts".
   * @param string|null $replica_id
   *   The replica's site ID; NULL for the site-wide setting.
   */
  public function get(string $path, ?string $replica_id = NULL): mixed {
    $pairing = $replica_id === NULL ? NULL : $this->pairing($replica_id);
    if ($pairing !== NULL && in_array(explode('.', $path)[0], self::KEYS, TRUE)) {
      $value = $pairing->getSetting($path);
      if ($value !== NULL) {
        return $value;
      }
    }
    return $this->siteWide($path);
  }

  /**
   * The pairing with a replica, by its site ID.
   */
  public function pairing(string $replica_id): ?ContentRelayReplicaPairing {
    $pairings = $this->entityTypeManager->getStorage('content_relay_replica_pairing')->loadByProperties(['replica_id' => $replica_id]);
    $pairing = reset($pairings);
    return $pairing instanceof ContentRelayReplicaPairing ? $pairing : NULL;
  }

  /**
   * Every pairing.
   *
   * @return \Drupal\content_relay_origin\Entity\ContentRelayReplicaPairing[]
   *   Pairings, by ID.
   */
  public function pairings(): array {
    $pairings = $this->entityTypeManager->getStorage('content_relay_replica_pairing')->loadMultiple();
    return array_filter($pairings, fn ($pairing) => $pairing instanceof ContentRelayReplicaPairing);
  }

  /**
   * A replica's pairing, made when missing.
   *
   * @param string $replica_id
   *   The replica's site ID.
   * @param string $label
   *   Its label, which the machine name is made from.
   */
  public function ensurePairing(string $replica_id, string $label): ContentRelayReplicaPairing {
    $pairing = $this->pairing($replica_id);
    if ($pairing !== NULL) {
      return $pairing;
    }
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($this->transliteration->transliterate($label, 'en', '_'))), '_') ?: 'replica';
    $base = substr($base, 0, 50);
    $storage = $this->entityTypeManager->getStorage('content_relay_replica_pairing');
    $id = $base;
    for ($i = 2; $storage->load($id) !== NULL; $i++) {
      $id = $base . '_' . $i;
    }
    $pairing = ContentRelayReplicaPairing::create([
      'id' => $id,
      'label' => $label,
      'replica_id' => $replica_id,
      'settings' => [],
    ]);
    $pairing->save();
    return $pairing;
  }

  /**
   * The site-wide sending settings, which pairings start from.
   */
  public function defaults(): array {
    $settings = array_intersect_key($this->configFactory->get('content_relay_origin.settings')->get(), array_flip(self::KEYS));
    $settings['encryption'] = $this->configFactory->get('content_relay.settings')->get('encryption');
    return $settings;
  }

  /**
   * A site-wide setting.
   */
  protected function siteWide(string $path): mixed {
    if (str_starts_with($path, 'encryption.') || $path === 'encryption') {
      return $this->configFactory->get('content_relay.settings')->get($path);
    }
    return $this->configFactory->get('content_relay_origin.settings')->get($path);
  }

}
