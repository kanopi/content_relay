<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\key\KeyInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates, rotates and retires this site's keys, and tells peers.
 *
 * A rotation is three steps, so there is always a key both sides trust:
 *
 * 1. Generate a new key: it is "active", known here but not used yet.
 * 2. Rotate to it: peers are sent the new public key, signed with the
 *    current one; then the new key becomes primary and the old one active.
 *    Both are accepted from this site, and both decrypt, during the overlap.
 * 3. Retire the old key once every peer has the new one: it is no longer
 *    used or accepted, and peers are told to stop accepting it.
 *
 * Keys are Key module entities with the file provider, so private keys never
 * reach configuration or the database.
 */
class KeyRotation {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyStore $keyStore,
    protected LoggerInterface $logger,
    protected AccountInterface $currentUser,
    protected ?KeyAnnouncerInterface $announcer = NULL,
  ) {}

  /**
   * Creates a new key, written to a file, with status active.
   *
   * @param \Drupal\content_relay\Key\KeyPurpose $purpose
   *   What it is for.
   * @param string $key_id
   *   Machine name of the new key entity.
   * @param string $path
   *   Where to write the private key. Keep it outside the web root.
   *
   * @return \Drupal\content_relay\Key\KeyPair
   *   The new key pair.
   *
   * @throws \InvalidArgumentException
   *   When the key entity or the file exists already, or the file cannot be
   *   written.
   */
  public function generate(KeyPurpose $purpose, string $key_id, string $path): KeyPair {
    $storage = $this->entityTypeManager->getStorage('key');
    if ($storage->load($key_id) !== NULL) {
      throw new \InvalidArgumentException(sprintf('Key "%s" exists already.', $key_id));
    }
    if (file_exists($path)) {
      throw new \InvalidArgumentException(sprintf('%s exists already; it will not be overwritten.', $path));
    }
    [$pair, $material] = KeyPair::generate($purpose);
    $written = @file_put_contents($path, $material);
    if ($written === FALSE) {
      throw new \InvalidArgumentException(sprintf('%s cannot be written.', $path));
    }
    @chmod($path, 0600);
    $storage->create([
      'id' => $key_id,
      'label' => sprintf('Content Relay %s key %s', $purpose->value, $pair->id()),
      'key_type' => $purpose->keyTypeId(),
      'key_type_settings' => ['status' => KeyStore::ACTIVE],
      'key_provider' => 'file',
      'key_provider_settings' => ['file_location' => $path, 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE],
      'key_input' => 'none',
    ])->save();
    $this->keyStore->reset();
    $this->logger->notice('@user generated @purpose key @id (@key).', [
      '@user' => $this->currentUser->getAccountName(),
      '@purpose' => $purpose->value,
      '@id' => $pair->id(),
      '@key' => $key_id,
    ]);
    return $pair;
  }

  /**
   * Makes an active key primary, after telling peers about it.
   *
   * @return array<string, bool>
   *   By peer, whether it confirmed the new key.
   *
   * @throws \RuntimeException
   *   When the key cannot be used, or a peer that must confirm did not; then
   *   nothing changed.
   */
  public function rotate(KeyPurpose $purpose, string $key_id): array {
    $key = $this->loadKey($purpose, $key_id);
    if (KeyStore::statusOf($key) !== KeyStore::ACTIVE) {
      throw new \RuntimeException(sprintf('Key "%s" must be active to become primary; it is %s.', $key_id, KeyStore::statusOf($key)));
    }
    $current = $this->primaryEntity($purpose);
    $results = $this->announce();
    if ($this->announcer?->requiresAll() && in_array(FALSE, $results, TRUE)) {
      throw new \RuntimeException(sprintf('Not every peer confirmed the new key (%s); nothing was changed.', implode(', ', array_keys(array_filter($results, fn ($ok) => !$ok)))));
    }
    if ($current !== NULL) {
      $this->setStatus($current, KeyStore::ACTIVE);
    }
    $this->setStatus($key, KeyStore::PRIMARY);
    // Peers learn the new statuses too.
    $results = $this->announce() + $results;
    $this->logger->notice('@user made @purpose key @key primary@previous.', [
      '@user' => $this->currentUser->getAccountName(),
      '@purpose' => $purpose->value,
      '@key' => $key_id,
      '@previous' => $current === NULL ? '' : sprintf(' in place of %s, which stays active', $current->id()),
    ]);
    return $results;
  }

  /**
   * Retires a key that is no longer primary, and tells peers.
   *
   * @return array<string, bool>
   *   By peer, whether it confirmed.
   *
   * @throws \RuntimeException
   *   When the key is primary.
   */
  public function retire(KeyPurpose $purpose, string $key_id): array {
    $key = $this->loadKey($purpose, $key_id);
    if (KeyStore::statusOf($key) === KeyStore::PRIMARY) {
      throw new \RuntimeException(sprintf('Key "%s" is primary; rotate to another key first.', $key_id));
    }
    $this->setStatus($key, KeyStore::RETIRED);
    $results = $this->announce();
    $this->logger->notice('@user retired @purpose key @key.', [
      '@user' => $this->currentUser->getAccountName(),
      '@purpose' => $purpose->value,
      '@key' => $key_id,
    ]);
    return $results;
  }

  /**
   * Sends peers the current keys.
   *
   * @return array<string, bool>
   *   By peer, whether it confirmed.
   */
  protected function announce(): array {
    return $this->announcer?->announce() ?? [];
  }

  /**
   * A Content Relay key entity of a purpose.
   *
   * @throws \RuntimeException
   *   When there is none.
   */
  protected function loadKey(KeyPurpose $purpose, string $key_id): KeyInterface {
    $key = $this->entityTypeManager->getStorage('key')->load($key_id);
    if (!$key instanceof KeyInterface || $key->get('key_type') !== $purpose->keyTypeId()) {
      throw new \RuntimeException(sprintf('There is no Content Relay %s key "%s".', $purpose->value, $key_id));
    }
    return $key;
  }

  /**
   * The key entity that is primary for a purpose, if any.
   */
  protected function primaryEntity(KeyPurpose $purpose): ?KeyInterface {
    foreach ($this->entityTypeManager->getStorage('key')->loadByProperties(['key_type' => $purpose->keyTypeId()]) as $key) {
      if ($key instanceof KeyInterface && KeyStore::statusOf($key) === KeyStore::PRIMARY) {
        return $key;
      }
    }
    return NULL;
  }

  /**
   * Changes a key's status.
   */
  protected function setStatus(KeyInterface $key, string $status): void {
    // The plugin's configuration is what the entity saves as its settings.
    $type = $key->getKeyType();
    if ($type instanceof ConfigurableInterface) {
      $type->setConfiguration(['status' => $status]);
    }
    $key->set('key_type_settings', ['status' => $status]);
    $key->save();
    $this->keyStore->reset();
  }

}
