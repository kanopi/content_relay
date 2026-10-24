<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * This site's own key pairs, held by the Key module.
 *
 * Every key entity of a Content Relay key type is one of this site's key
 * pairs; there is no separate list to maintain. The key type's settings give
 * its status: primary (used for new messages), active (still accepted or used
 * to decrypt, during a rotation) or retired (refused). The value lives wherever
 * the key's provider keeps it.
 *
 * Providers that keep the value in exported configuration or in the database
 * are refused, so a config export or a database dump never contains a private
 * key. Use the file or environment variable provider.
 *
 * @see \Drupal\content_relay\Plugin\KeyType\ContentRelayKeyTypeBase
 */
class KeyStore {

  public const PRIMARY = 'primary';
  public const ACTIVE = 'active';
  public const RETIRED = 'retired';

  /**
   * Key providers whose storage is configuration or the database.
   */
  public const REFUSED_PROVIDERS = ['config', 'state'];

  /**
   * Key pairs already read, by purpose and key ID.
   *
   * @var array<string, array<string, array{pair: \Drupal\content_relay\Key\KeyPair, status: string, key: string}>>
   */
  protected array $pairs = [];

  public function __construct(
    protected KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * The key pair used for new messages.
   *
   * @throws \RuntimeException
   *   When no primary key exists or it cannot be read.
   */
  public function getPrimary(KeyPurpose $purpose): KeyPair {
    foreach ($this->load($purpose) as $entry) {
      if ($entry['status'] === self::PRIMARY) {
        return $entry['pair'];
      }
    }
    throw new \RuntimeException(sprintf('No primary Content Relay %s key exists. Create a key of type "%s" with status primary.', $purpose->value, $purpose->keyTypeId()));
  }

  /**
   * A usable key pair by ID: primary or active, never retired.
   */
  public function get(KeyPurpose $purpose, string $key_id): ?KeyPair {
    return $this->load($purpose)[$key_id]['pair'] ?? NULL;
  }

  /**
   * Public keys to give to peers, keyed by key ID.
   *
   * @return array<string, array{public_key: string, status: string}>
   *   Base64 public keys and their status, retired keys excluded.
   */
  public function getPublicKeys(KeyPurpose $purpose): array {
    $keys = [];
    foreach ($this->load($purpose) as $id => $entry) {
      $keys[$id] = ['public_key' => base64_encode($entry['pair']->publicKey), 'status' => $entry['status']];
    }
    return $keys;
  }

  /**
   * Forgets key pairs already read, after keys change.
   */
  public function reset(): void {
    $this->pairs = [];
  }

  /**
   * The Content Relay status of a key entity.
   */
  public static function statusOf(KeyInterface $key): string {
    $settings = $key->get('key_type_settings');
    return is_array($settings) && is_string($settings['status'] ?? NULL) ? $settings['status'] : self::ACTIVE;
  }

  /**
   * Checks that a key entity may hold a Content Relay private key.
   *
   * @throws \InvalidArgumentException
   *   When its provider stores the value in config or the database.
   */
  public static function assertAcceptableProvider(KeyInterface $key): void {
    $provider = (string) $key->get('key_provider');
    if (in_array($provider, self::REFUSED_PROVIDERS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Key "%s" uses the "%s" provider, which keeps the value in configuration or the database. Use a file or environment variable provider for private keys.', $key->id(), $provider));
    }
  }

  /**
   * Reads and checks every key of a purpose's key type.
   *
   * @return array<string, array{pair: \Drupal\content_relay\Key\KeyPair, status: string, key: string}>
   *   Primary and active key pairs by key ID, in key entity ID order.
   *
   * @throws \RuntimeException
   *   When a key is misconfigured or its value is not a valid key, or more
   *   than one is primary.
   */
  protected function load(KeyPurpose $purpose): array {
    if (isset($this->pairs[$purpose->value])) {
      return $this->pairs[$purpose->value];
    }
    $keys = $this->keyRepository->getKeysByType($purpose->keyTypeId());
    ksort($keys);
    $pairs = [];
    $primaries = 0;
    foreach ($keys as $id => $key) {
      $status = self::statusOf($key);
      // Retired keys are never used, so their value is not even read: a
      // retired key whose file has been removed must not break the others.
      if ($status === self::RETIRED) {
        continue;
      }
      try {
        self::assertAcceptableProvider($key);
        $pair = KeyPair::fromMaterial($purpose, (string) $key->getKeyValue());
      }
      catch (\InvalidArgumentException $e) {
        throw new \RuntimeException(sprintf('Content Relay %s key "%s" cannot be used: %s', $purpose->value, $id, $e->getMessage()), 0, $e);
      }
      $primaries += (int) ($status === self::PRIMARY);
      $pairs[$pair->id()] = ['pair' => $pair, 'status' => $status, 'key' => (string) $id];
    }
    if ($primaries > 1) {
      throw new \RuntimeException(sprintf('More than one Content Relay %s key is primary.', $purpose->value));
    }
    return $this->pairs[$purpose->value] = $pairs;
  }

}
