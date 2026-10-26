<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * How content is received: a pairing's settings, or the site-wide ones.
 *
 * Every setting about receiving is read through here, from the pairing the
 * content comes from. What a pairing leaves out follows the site-wide
 * settings, which are also the defaults for new pairings.
 */
class ReceivingSettings {

  /**
   * Settings a pairing can have.
   *
   * The rest of the site-wide settings are not about one origin.
   */
  public const KEYS = [
    'origin_allowed_ips',
    'failures',
    'external_references',
    'local_only',
    'languages',
    'conflicts',
    'moderation',
    'reconcile',
    'detach',
    'files',
    'processing',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ActivePairing $active,
  ) {}

  /**
   * A receiving setting.
   *
   * @param string $path
   *   Dotted path, such as "conflicts.on_event".
   * @param string|null $pairing
   *   The pairing; NULL for the active one.
   */
  public function get(string $path, ?string $pairing = NULL): mixed {
    $entity = $this->pairing($pairing);
    if ($entity !== NULL && in_array(explode('.', $path)[0], self::KEYS, TRUE)) {
      $value = $entity->getSetting($path);
      if ($value !== NULL) {
        return $value;
      }
    }
    return $this->configFactory->get('content_relay_replica.settings')->get($path);
  }

  /**
   * A pairing, or the active one.
   */
  public function pairing(?string $id = NULL): ?ContentRelayOriginPairing {
    $pairing = $this->entityTypeManager->getStorage('content_relay_origin_pairing')->load($id ?? $this->active->id());
    return $pairing instanceof ContentRelayOriginPairing ? $pairing : NULL;
  }

  /**
   * The active pairing's settings, made when missing.
   *
   * @param string $label
   *   The origin's label, for a new pairing.
   */
  public function ensurePairing(string $label): ContentRelayOriginPairing {
    $pairing = $this->pairing();
    if ($pairing === NULL) {
      $pairing = ContentRelayOriginPairing::create([
        'id' => $this->active->id(),
        'label' => $label,
        'settings' => $this->defaults(),
      ]);
      $pairing->save();
    }
    return $pairing;
  }

  /**
   * Copies the site-wide receiving settings into the active pairing.
   *
   * While a site has one origin, its settings form edits both.
   */
  public function copyToPairing(): void {
    $pairing = $this->pairing();
    if ($pairing !== NULL) {
      foreach ($this->defaults() as $key => $value) {
        $pairing->setSetting($key, $value);
      }
      $pairing->save();
    }
  }

  /**
   * Settings for a new pairing: the site-wide receiving settings.
   */
  public function defaults(): array {
    $settings = $this->configFactory->get('content_relay_replica.settings')->get();
    return array_intersect_key($settings, array_flip(self::KEYS));
  }

}
