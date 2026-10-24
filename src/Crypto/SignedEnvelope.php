<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

/**
 * A signed message as it travels.
 *
 * The outer fields say who sent it and which key to check it with. They are
 * routing hints only: the verifier checks each against the signed message, so
 * changing one in transit is caught.
 *
 * Unencrypted, the envelope carries the message and its signature in the
 * clear. Encrypted, it carries only the ciphertext of both (sign, then
 * encrypt), plus the ID of the recipient key it was sealed to.
 */
final class SignedEnvelope {

  public const SCHEMA_VERSION = 1;

  /**
   * Constructs a SignedEnvelope.
   *
   * @param string $sender
   *   Sender site ID.
   * @param string $signingKeyId
   *   ID of the sender's signing key.
   * @param \Drupal\content_relay\Crypto\EncryptionMode $encryption
   *   How the message travels.
   * @param array|null $message
   *   The signed message, when not encrypted.
   * @param string|null $signature
   *   Base64 detached signature, when not encrypted.
   * @param string|null $ciphertext
   *   Base64 ciphertext of message and signature, when encrypted.
   * @param string|null $encryptionKeyId
   *   ID of the recipient key it was sealed to, when encrypted.
   */
  public function __construct(
    public readonly string $sender,
    public readonly string $signingKeyId,
    public readonly EncryptionMode $encryption,
    public readonly ?array $message = NULL,
    public readonly ?string $signature = NULL,
    public readonly ?string $ciphertext = NULL,
    public readonly ?string $encryptionKeyId = NULL,
  ) {}

  /**
   * Returns the envelope as sent on the wire.
   */
  public function toArray(): array {
    $data = [
      'v' => self::SCHEMA_VERSION,
      'sender' => $this->sender,
      'signing_key_id' => $this->signingKeyId,
      'encryption' => $this->encryption->value,
    ];
    if ($this->encryption === EncryptionMode::None) {
      $data['message'] = $this->message;
      $data['signature'] = $this->signature;
    }
    else {
      $data['encryption_key_id'] = $this->encryptionKeyId;
      $data['ciphertext'] = $this->ciphertext;
    }
    return $data;
  }

  /**
   * Reads an envelope as received.
   *
   * @throws \Drupal\content_relay\Crypto\VerificationException
   *   When it is malformed.
   */
  public static function fromArray(array $data): self {
    if (($data['v'] ?? NULL) !== self::SCHEMA_VERSION) {
      throw new VerificationException(VerificationException::MALFORMED, 'Unsupported envelope version.');
    }
    if (!is_string($data['sender'] ?? NULL) || !is_string($data['signing_key_id'] ?? NULL) || !is_string($data['encryption'] ?? NULL)) {
      throw new VerificationException(VerificationException::MALFORMED, 'The envelope needs a sender, a signing key ID and an encryption mode.');
    }
    $mode = EncryptionMode::tryFrom($data['encryption']) ?? throw new VerificationException(VerificationException::MALFORMED, 'Unknown encryption mode.');
    if ($mode === EncryptionMode::None) {
      if (!is_array($data['message'] ?? NULL) || !is_string($data['signature'] ?? NULL)) {
        throw new VerificationException(VerificationException::MALFORMED, 'An unencrypted envelope needs a message and a signature.');
      }
      return new self($data['sender'], $data['signing_key_id'], $mode, $data['message'], $data['signature']);
    }
    if (!is_string($data['ciphertext'] ?? NULL) || !is_string($data['encryption_key_id'] ?? NULL)) {
      throw new VerificationException(VerificationException::MALFORMED, 'An encrypted envelope needs a ciphertext and an encryption key ID.');
    }
    return new self($data['sender'], $data['signing_key_id'], $mode, NULL, NULL, $data['ciphertext'], $data['encryption_key_id']);
  }

}
