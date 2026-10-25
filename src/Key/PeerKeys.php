<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

/**
 * A peer's public keys, as exchanged at pairing and in later updates.
 *
 * A key set maps key ID to the base64 public key and its status on the peer:
 * primary (what it uses now) or active (still in use during a rotation).
 * Retired keys are not listed; a key that disappears from a peer's set is no
 * longer accepted from it.
 */
final class PeerKeys {

  /**
   * Checks an offered key set.
   *
   * Each key must decode, be the right size, and have the ID derived from it.
   *
   * @return array<string, array{public_key: string, status: string}>
   *   The keys.
   *
   * @throws \InvalidArgumentException
   *   When a key is malformed.
   */
  public static function check(mixed $keys, KeyPurpose $purpose): array {
    if (!is_array($keys)) {
      throw new \InvalidArgumentException(sprintf('The %s keys are missing.', $purpose->value));
    }
    $size = $purpose === KeyPurpose::Signing ? \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : \SODIUM_CRYPTO_BOX_PUBLICKEYBYTES;
    $checked = [];
    foreach ($keys as $id => $key) {
      $public = is_string($key['public_key'] ?? NULL) ? base64_decode($key['public_key'], TRUE) : FALSE;
      // An ID that happens to be all digits is an integer key in PHP.
      if ($public === FALSE || strlen($public) !== $size || KeyPair::idFor($purpose, $public) !== (string) $id) {
        throw new \InvalidArgumentException(sprintf('A %s key is malformed.', $purpose->value));
      }
      $status = in_array($key['status'] ?? NULL, [KeyStore::PRIMARY, KeyStore::ACTIVE], TRUE) ? $key['status'] : KeyStore::ACTIVE;
      $checked[(string) $id] = ['public_key' => $key['public_key'], 'status' => $status];
    }
    return $checked;
  }

  /**
   * The key to use with a peer: its primary, else any active one.
   *
   * @return array{id: string, public_key: string}|null
   *   The key ID and raw public key.
   */
  public static function preferred(array $keys): ?array {
    uasort($keys, fn (array $a, array $b) => (($b['status'] ?? '') === KeyStore::PRIMARY) <=> (($a['status'] ?? '') === KeyStore::PRIMARY));
    foreach ($keys as $id => $key) {
      if (($key['status'] ?? '') === KeyStore::RETIRED) {
        continue;
      }
      $public = base64_decode((string) ($key['public_key'] ?? ''), TRUE);
      if ($public !== FALSE && $public !== '') {
        return ['id' => (string) $id, 'public_key' => $public];
      }
    }
    return NULL;
  }

}
