<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\File;

use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;

/**
 * Per-file encryption keys, derived rather than stored.
 *
 * A file's key for a replica is a keyed hash, under this site's private
 * encryption key, of the file's UUID, the replica and the file's checksum. It
 * is different for every file, replica and version of the content,
 * unguessable without the private key, and the same when the event is built
 * and when the file is downloaded, so it never has to be kept anywhere. The
 * replica receives it inside the sealed event.
 */
class FileKeys {

  /**
   * Separates this use of the key from any other.
   */
  public const CONTEXT = "content_relay.file_key.v1\n";

  public function __construct(
    protected KeyStore $keyStore,
  ) {}

  /**
   * The ID of the encryption key new file keys are derived from.
   */
  public function currentKeyId(): string {
    return $this->keyStore->getPrimary(KeyPurpose::Encryption)->id();
  }

  /**
   * A file's key for a replica, raw bytes.
   *
   * @throws \RuntimeException
   *   When the encryption key is not available (unknown or retired).
   */
  public function derive(string $encryption_key_id, string $uuid, string $replica_id, string $sha256): string {
    $key = $this->keyStore->get(KeyPurpose::Encryption, $encryption_key_id)
      ?? throw new \RuntimeException(sprintf('Encryption key %s is not available.', $encryption_key_id));
    $input = self::CONTEXT . $uuid . "\n" . $replica_id . "\n" . $sha256;
    return $key->withSecret(fn (string $secret) => sodium_crypto_generichash($input, sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_GENERICHASH_KEYBYTES), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
  }

}
