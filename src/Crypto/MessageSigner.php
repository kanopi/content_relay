<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Hash\CanonicalJson;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\SiteIdentity;

/**
 * Signs outgoing messages, and seals them when the peer's contract says so.
 *
 * The signature covers the canonical JSON of the whole message together with
 * the key ID, behind a fixed context string. The context string means a
 * signature made here can never be passed off as a signature over something
 * else that happens to share the key, such as a JWT.
 *
 * Sign, then encrypt: a sealed message is the signed message and its
 * signature, sealed together to the recipient's X25519 key, so in transit
 * only the sender, key IDs and mode are visible. The mode is inside the
 * signed message, so it cannot be changed on the way.
 *
 * The signing key is the primary one, unless the peer has not yet learned it
 * during a rotation; then it is one the peer already trusts.
 */
class MessageSigner {

  /**
   * Prefixed to the signed bytes, to separate this use of the key from others.
   */
  public const CONTEXT = "content_relay.message.v1\n";

  public function __construct(
    protected KeyStore $keyStore,
    protected SiteIdentity $siteIdentity,
    protected TimeInterface $time,
    protected UuidInterface $uuid,
    protected PeerPolicy $peerPolicy,
  ) {}

  /**
   * Builds and signs a message to a peer.
   *
   * @param string $recipient
   *   The peer's site ID.
   * @param string $type
   *   The message type.
   * @param array $body
   *   The payload.
   * @param int|null $sequence
   *   Stream position, for events.
   * @param \Drupal\content_relay\Crypto\EncryptionMode|null $encryption
   *   How it will travel; NULL for what the contract with the peer says.
   *
   * @throws \RuntimeException
   *   When it is to be sealed and the peer has no encryption key.
   */
  public function sign(string $recipient, string $type, array $body, ?int $sequence = NULL, ?EncryptionMode $encryption = NULL): SignedEnvelope {
    $encryption ??= $this->peerPolicy->sendMode($recipient) ?? EncryptionMode::None;
    $message = new Message(
      $this->uuid->generate(),
      $type,
      $this->siteIdentity->getSiteId(),
      $recipient,
      $this->time->getCurrentTime(),
      $encryption,
      $body,
      $sequence,
    );
    $envelope = $this->signMessage($message, $this->signingKeyFor($recipient));
    if ($encryption === EncryptionMode::None) {
      return $envelope;
    }
    $key = $this->peerPolicy->peerEncryptionKey($recipient)
      ?? throw new \RuntimeException(sprintf('Site %s has no encryption key to seal a message to.', $recipient));
    return self::seal($envelope, $key['id'], $key['public_key']);
  }

  /**
   * Signs a prepared message with a given key.
   */
  public function signMessage(Message $message, KeyPair $key): SignedEnvelope {
    $signature = $key->sign(self::signedBytes($key->id(), $message->toArray()));
    return new SignedEnvelope($message->sender, $key->id(), $message->encryption, $message->toArray(), base64_encode($signature));
  }

  /**
   * Seals a signed envelope to a recipient key.
   */
  public static function seal(SignedEnvelope $signed, string $key_id, string $public_key): SignedEnvelope {
    $plain = CanonicalJson::encode(['message' => $signed->message, 'signature' => $signed->signature]);
    return new SignedEnvelope($signed->sender, $signed->signingKeyId, EncryptionMode::Sealed, NULL, NULL, base64_encode(KeyPair::seal($plain, $public_key)), $key_id);
  }

  /**
   * The signing key to use for a peer.
   */
  protected function signingKeyFor(string $recipient): KeyPair {
    $primary = $this->keyStore->getPrimary(KeyPurpose::Signing);
    $trusted = $this->peerPolicy->trustedSigningKeyIds($recipient);
    if ($trusted === NULL || in_array($primary->id(), $trusted, TRUE)) {
      return $primary;
    }
    foreach ($trusted as $key_id) {
      if ($key = $this->keyStore->get(KeyPurpose::Signing, $key_id)) {
        return $key;
      }
    }
    return $primary;
  }

  /**
   * The exact bytes a signature covers.
   */
  public static function signedBytes(string $key_id, array $message): string {
    return self::CONTEXT . CanonicalJson::encode(['key_id' => $key_id, 'message' => $message]);
  }

}
