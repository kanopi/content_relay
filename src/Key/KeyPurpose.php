<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

/**
 * What a key pair is for. A key is never used for more than one purpose.
 */
enum KeyPurpose: string {

  // Ed25519, for signatures on every message and JWT.
  case Signing = 'signing';
  // X25519, for encrypting messages and file keys to a peer.
  case Encryption = 'encryption';

  /**
   * The prefix that marks stored secret material for this purpose.
   *
   * Stored keys carry it so that a key entity pointed at the wrong slot is
   * refused instead of being used as the wrong kind of key.
   */
  public function materialPrefix(): string {
    return match ($this) {
      self::Signing => 'content-relay-ed25519:',
      self::Encryption => 'content-relay-x25519:',
    };
  }

  /**
   * The settings key listing references for this purpose.
   */
  public function settingsKey(): string {
    return match ($this) {
      self::Signing => 'signing_keys',
      self::Encryption => 'encryption_keys',
    };
  }

}
