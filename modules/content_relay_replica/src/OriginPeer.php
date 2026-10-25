<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Core\State\StateInterface;

/**
 * The origin this replica receives from.
 *
 * Kept in state, not configuration: pairing happens on one environment, and a
 * config import from another must not change who this site trusts. Nothing
 * here is secret; it is the origin's address and public keys.
 */
class OriginPeer {

  public const STATE_KEY = 'content_relay_replica.origin';

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * The origin, or NULL before pairing.
   *
   * @return array{site_id: string, label: string, url: string, signing_keys: array<string, array{public_key: string, status: string}>, encryption_keys: array}|null
   *   The origin.
   */
  public function get(): ?array {
    $origin = $this->state->get(self::STATE_KEY);
    return is_array($origin) && isset($origin['site_id']) ? $origin : NULL;
  }

  /**
   * The origin's site ID, or NULL before pairing.
   */
  public function getSiteId(): ?string {
    return $this->get()['site_id'] ?? NULL;
  }

  /**
   * The origin's label, for revision log messages.
   */
  public function getLabel(): string {
    $origin = $this->get();
    return $origin['label'] ?? $origin['site_id'] ?? 'origin';
  }

  /**
   * Replaces the origin's public keys.
   *
   * @param array $signing_keys
   *   Checked signing keys; see PeerKeys::check().
   * @param array $encryption_keys
   *   Checked encryption keys.
   */
  public function setKeys(array $signing_keys, array $encryption_keys): void {
    $origin = $this->get();
    if ($origin === NULL) {
      return;
    }
    $origin['signing_keys'] = $signing_keys;
    $origin['encryption_keys'] = $encryption_keys;
    $this->state->set(self::STATE_KEY, $origin);
  }

  /**
   * Stores the origin.
   *
   * @param array $origin
   *   With site_id, label, url, signing_keys and encryption_keys.
   */
  public function set(array $origin): void {
    if (empty($origin['site_id']) || !isset($origin['url']) || !str_starts_with(strtolower($origin['url']), 'https://')) {
      throw new \InvalidArgumentException('An origin needs a site ID and an HTTPS URL.');
    }
    $origin += ['label' => $origin['site_id'], 'signing_keys' => [], 'encryption_keys' => []];
    $this->state->set(self::STATE_KEY, $origin);
  }

}
