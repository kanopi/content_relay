<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

/**
 * A key pair held in memory, with its derived ID.
 *
 * The secret half never leaves this object except to be handed to libsodium.
 * The object refuses to be serialized and hides the secret from var_dump(), so
 * it cannot end up in a cache entry, a queue item or a log by accident.
 */
final class KeyPair {

  /**
   * Constructs a KeyPair. Use fromSecret() or generate().
   */
  private function __construct(
    public readonly KeyPurpose $purpose,
    public readonly string $publicKey,
    #[\SensitiveParameter]
    private readonly string $secretKey,
  ) {}

  /**
   * Builds a key pair from stored secret material.
   *
   * @param \Drupal\content_relay\Key\KeyPurpose $purpose
   *   What the key is for.
   * @param string $material
   *   The stored value: the purpose's prefix and the base64 secret key.
   *
   * @throws \InvalidArgumentException
   *   When the material is not a key for this purpose.
   */
  public static function fromMaterial(KeyPurpose $purpose, #[\SensitiveParameter] string $material): self {
    $material = trim($material);
    $prefix = $purpose->materialPrefix();
    if (!str_starts_with($material, $prefix)) {
      throw new \InvalidArgumentException(sprintf('Key material is not a Content Relay %s key.', $purpose->value));
    }
    $secret = base64_decode(substr($material, strlen($prefix)), TRUE);
    if ($secret === FALSE) {
      throw new \InvalidArgumentException('Key material is not valid base64.');
    }
    return match ($purpose) {
      KeyPurpose::Signing => strlen($secret) === \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
        ? new self($purpose, sodium_crypto_sign_publickey_from_secretkey($secret), $secret)
        : throw new \InvalidArgumentException('An Ed25519 secret key is 64 bytes.'),
      KeyPurpose::Encryption => strlen($secret) === \SODIUM_CRYPTO_BOX_SECRETKEYBYTES
        ? new self($purpose, sodium_crypto_box_publickey_from_secretkey($secret), $secret)
        : throw new \InvalidArgumentException('An X25519 secret key is 32 bytes.'),
    };
  }

  /**
   * Generates a new key pair and returns it with its material to store.
   *
   * @return array{0: self, 1: string}
   *   The key pair and the string to put in a key entity.
   */
  public static function generate(KeyPurpose $purpose): array {
    $secret = match ($purpose) {
      KeyPurpose::Signing => sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()),
      KeyPurpose::Encryption => sodium_crypto_box_secretkey(sodium_crypto_box_keypair()),
    };
    $material = $purpose->materialPrefix() . base64_encode($secret);
    return [self::fromMaterial($purpose, $material), $material];
  }

  /**
   * The key ID, derived from the public key.
   *
   * Derived rather than assigned, so both sides compute the same ID from the
   * public key alone and there is nothing to keep in step.
   */
  public function id(): string {
    return self::idFor($this->purpose, $this->publicKey);
  }

  /**
   * The key ID for a public key.
   */
  public static function idFor(KeyPurpose $purpose, string $public_key): string {
    return substr(hash('sha256', $purpose->value . ':' . $public_key), 0, 16);
  }

  /**
   * Signs bytes with the secret key.
   *
   * @return string
   *   The raw 64-byte detached signature.
   */
  public function sign(string $bytes): string {
    if ($this->purpose !== KeyPurpose::Signing) {
      throw new \LogicException('This is not a signing key.');
    }
    return sodium_crypto_sign_detached($bytes, $this->secretKey);
  }

  /**
   * Seals bytes to a peer's X25519 public key.
   *
   * Anyone can seal to a public key; only the holder of the secret key can
   * open the result. Authenticity comes from the signature inside.
   */
  public static function seal(string $bytes, string $public_key): string {
    return sodium_crypto_box_seal($bytes, $public_key);
  }

  /**
   * Opens bytes sealed to this key.
   *
   * @return string|null
   *   The bytes, or NULL when they were not sealed to this key or were
   *   altered.
   */
  public function open(string $sealed): ?string {
    if ($this->purpose !== KeyPurpose::Encryption) {
      throw new \LogicException('This is not an encryption key.');
    }
    $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($this->secretKey, $this->publicKey);
    $bytes = sodium_crypto_box_seal_open($sealed, $pair);
    sodium_memzero($pair);
    return $bytes === FALSE ? NULL : $bytes;
  }

  /**
   * Hands the secret key to a libsodium call.
   *
   * @param callable(string): mixed $operation
   *   Receives the raw secret key.
   */
  public function withSecret(callable $operation): mixed {
    return $operation($this->secretKey);
  }

  /**
   * Hides the secret key from debugging output.
   */
  public function __debugInfo(): array {
    return ['purpose' => $this->purpose, 'id' => $this->id()];
  }

  /**
   * Refuses serialization.
   */
  public function __serialize(): array {
    throw new \LogicException('Key pairs cannot be serialized.');
  }

}
