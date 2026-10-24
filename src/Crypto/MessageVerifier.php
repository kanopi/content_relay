<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\SiteIdentity;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Checks incoming messages and returns them only when every check passes.
 *
 * The checks run in an order chosen so that nothing an attacker controls is
 * acted on before the signature is known to be good. In particular, the
 * message ID is only recorded for replay protection after the signature
 * checks out; otherwise anyone could burn IDs in advance with forged
 * messages.
 *
 * 1. The envelope is well formed and its version known.
 * 2. Its encryption mode is supported.
 * 3. The sender and key ID name a known, unretired key.
 * 4. The signature verifies.
 * 5. The envelope's sender and mode match the signed message's.
 * 6. The message is addressed to this site.
 * 7. Its timestamp is within the clock window.
 * 8. Its ID has not been seen before.
 */
class MessageVerifier {

  public function __construct(
    protected PublicKeyResolverInterface $publicKeys,
    protected ReplayGuardInterface $replayGuard,
    protected SiteIdentity $siteIdentity,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Verifies a received envelope.
   *
   * @param array $data
   *   The decoded envelope.
   *
   * @return \Drupal\content_relay\Crypto\Message
   *   The verified message.
   *
   * @throws \Drupal\content_relay\Crypto\VerificationException
   *   When any check fails.
   */
  public function verify(array $data): Message {
    $envelope = SignedEnvelope::fromArray($data);
    if ($envelope->encryption !== EncryptionMode::None) {
      throw new VerificationException(VerificationException::UNSUPPORTED_ENCRYPTION, 'Encrypted messages are not supported yet.');
    }

    $public_key = $this->publicKeys->getPublicKey($envelope->sender, KeyPurpose::Signing, $envelope->signingKeyId);
    if ($public_key === NULL || strlen($public_key) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
      throw new VerificationException(VerificationException::UNKNOWN_KEY, sprintf('No usable signing key "%s" for sender "%s".', $envelope->signingKeyId, $envelope->sender));
    }

    $signature = base64_decode((string) $envelope->signature, TRUE);
    try {
      $bytes = MessageSigner::signedBytes($envelope->signingKeyId, (array) $envelope->message);
    }
    catch (\JsonException | \InvalidArgumentException $e) {
      throw new VerificationException(VerificationException::MALFORMED, 'The message cannot be encoded canonically.', $e);
    }
    if ($signature === FALSE || strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($signature, $bytes, $public_key)) {
      throw new VerificationException(VerificationException::BAD_SIGNATURE, 'The signature does not verify.');
    }

    try {
      $message = Message::fromArray((array) $envelope->message);
    }
    catch (\InvalidArgumentException $e) {
      throw new VerificationException(VerificationException::MALFORMED, $e->getMessage(), $e);
    }
    if ($message->sender !== $envelope->sender || $message->encryption !== $envelope->encryption) {
      throw new VerificationException(VerificationException::HEADER_MISMATCH, 'The envelope does not match the signed message.');
    }
    if ($message->recipient !== $this->siteIdentity->getSiteId()) {
      throw new VerificationException(VerificationException::WRONG_RECIPIENT, 'The message is addressed to another site.');
    }

    $window = $this->getClockWindow();
    $now = $this->time->getCurrentTime();
    if (abs($now - $message->timestamp) > $window) {
      throw new VerificationException(VerificationException::STALE, sprintf('The message timestamp is more than %d seconds from this site\'s clock.', $window));
    }
    // Kept until the message would be refused as stale anyway, with a margin
    // for a clock that moves backwards.
    if (!$this->replayGuard->claim($message->sender, $message->id, $message->timestamp + 2 * $window)) {
      throw new VerificationException(VerificationException::REPLAYED, 'The message was already received.');
    }
    return $message;
  }

  /**
   * The accepted clock difference, in seconds.
   */
  public function getClockWindow(): int {
    return max(1, (int) ($this->configFactory->get('content_relay.settings')->get('signature.clock_window') ?: 300));
  }

}
