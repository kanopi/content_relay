<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

/**
 * How messages to and from one peer travel, as the contract with it says.
 *
 * Each role module provides one, tagged content_relay_peer_policy: the origin
 * knows its replicas' contracts, a replica knows its origin's. Every method
 * may answer NULL for a peer it does not know, and the next policy is asked.
 */
interface PeerPolicyInterface {

  /**
   * The mode to send messages to a peer in.
   */
  public function sendMode(string $peer): ?EncryptionMode;

  /**
   * The peer's encryption key to seal messages to.
   *
   * @return array{id: string, public_key: string}|null
   *   The key ID and the raw X25519 public key.
   */
  public function peerEncryptionKey(string $peer): ?array;

  /**
   * Which of this site's signing keys the peer is known to accept.
   *
   * During a key rotation a peer that has not yet acknowledged the new key
   * is sent messages signed with one it already trusts.
   *
   * @return string[]|null
   *   Key IDs the peer accepts, or NULL to use the primary key.
   */
  public function trustedSigningKeyIds(string $peer): ?array;

  /**
   * Whether an unencrypted message from a peer is acceptable now.
   */
  public function allowsUnencrypted(string $peer): ?bool;

}
