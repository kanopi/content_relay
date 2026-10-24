<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\content_relay\Key\KeyPurpose;

/**
 * Finds a peer's public keys.
 *
 * The origin knows its replicas from its registry; a replica knows its origin
 * from pairing. Each role module tags a service implementing this with
 * "content_relay_public_key_resolver", and the verifier asks each in turn.
 */
interface PublicKeyResolverInterface {

  /**
   * A peer's public key, if the peer and key are known and not retired.
   *
   * @param string $site_id
   *   The peer's site ID.
   * @param \Drupal\content_relay\Key\KeyPurpose $purpose
   *   Which kind of key.
   * @param string $key_id
   *   The key ID.
   *
   * @return string|null
   *   The raw public key, or NULL to refuse.
   */
  public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string;

}
