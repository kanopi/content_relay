<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * This site's own key pairs, read through the Key module.
 *
 * Settings hold only references: which key entity holds each private key, and
 * whether it is the primary key (used for new messages), an active one (still
 * accepted or used to decrypt, during a rotation) or retired (refused). The
 * key material lives wherever the key entity's provider puts it.
 *
 * Providers that keep the value in exported configuration or in the database
 * are refused, so a config export or a database dump never contains a private
 * key. Use the file or environment provider.
 */
class KeyStore {

  /**
   * Key providers whose storage is configuration or the database.
   */
  public const REFUSED_PROVIDERS = ['config', 'state'];

  /**
   * Key pairs already read, by purpose and key ID.
   *
   * @var array<string, array<string, array{pair: \Drupal\content_relay\Key\KeyPair, status: string}>>
   */
  protected array $pairs = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * The key pair used for new messages.
   *
   * @throws \RuntimeException
   *   When no primary key is configured or it cannot be read.
   */
  public function getPrimary(KeyPurpose $purpose): KeyPair {
    foreach ($this->load($purpose) as $entry) {
      if ($entry['status'] === 'primary') {
        return $entry['pair'];
      }
    }
    throw new \RuntimeException(sprintf('No primary %s key is configured for Content Relay.', $purpose->value));
  }

  /**
   * A usable key pair by ID: primary or active, never retired.
   */
  public function get(KeyPurpose $purpose, string $key_id): ?KeyPair {
    $entry = $this->load($purpose)[$key_id] ?? NULL;
    return $entry && $entry['status'] !== 'retired' ? $entry['pair'] : NULL;
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
      if ($entry['status'] !== 'retired') {
        $keys[$id] = ['public_key' => base64_encode($entry['pair']->publicKey), 'status' => $entry['status']];
      }
    }
    return $keys;
  }

  /**
   * Forgets key pairs already read, after settings change.
   */
  public function reset(): void {
    $this->pairs = [];
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
   * Reads and checks every configured key for a purpose.
   *
   * @return array<string, array{pair: \Drupal\content_relay\Key\KeyPair, status: string}>
   *   Key pairs by key ID.
   *
   * @throws \RuntimeException
   *   When a configured key is missing, misconfigured or not a valid key.
   */
  protected function load(KeyPurpose $purpose): array {
    if (isset($this->pairs[$purpose->value])) {
      return $this->pairs[$purpose->value];
    }
    $pairs = [];
    $primaries = 0;
    foreach ($this->configFactory->get('content_relay.settings')->get($purpose->settingsKey()) ?? [] as $reference) {
      $key = $this->keyRepository->getKey($reference['key']);
      if (!$key instanceof KeyInterface) {
        throw new \RuntimeException(sprintf('Content Relay %s key "%s" does not exist.', $purpose->value, $reference['key']));
      }
      try {
        self::assertAcceptableProvider($key);
        $pair = KeyPair::fromMaterial($purpose, (string) $key->getKeyValue());
      }
      catch (\InvalidArgumentException $e) {
        throw new \RuntimeException(sprintf('Content Relay %s key "%s" cannot be used: %s', $purpose->value, $reference['key'], $e->getMessage()), 0, $e);
      }
      $primaries += (int) ($reference['status'] === 'primary');
      $pairs[$pair->id()] = ['pair' => $pair, 'status' => $reference['status']];
    }
    if ($primaries > 1) {
      throw new \RuntimeException(sprintf('More than one primary Content Relay %s key is configured.', $purpose->value));
    }
    return $this->pairs[$purpose->value] = $pairs;
  }

}
