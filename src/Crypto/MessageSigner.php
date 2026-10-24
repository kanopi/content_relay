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
 * Signs outgoing messages with this site's primary signing key.
 *
 * The signature covers the canonical JSON of the whole message together with
 * the key ID, behind a fixed context string. The context string means a
 * signature made here can never be passed off as a signature over something
 * else that happens to share the key, such as a JWT.
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
   * @param \Drupal\content_relay\Crypto\EncryptionMode $encryption
   *   How it will travel. Only EncryptionMode::None is produced so far.
   */
  public function sign(string $recipient, string $type, array $body, ?int $sequence = NULL, EncryptionMode $encryption = EncryptionMode::None): SignedEnvelope {
    if ($encryption !== EncryptionMode::None) {
      throw new \LogicException('Encrypted messages are not implemented yet.');
    }
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
    return $this->signMessage($message, $this->keyStore->getPrimary(KeyPurpose::Signing));
  }

  /**
   * Signs a prepared message with a given key.
   */
  public function signMessage(Message $message, KeyPair $key): SignedEnvelope {
    $signature = $key->sign(self::signedBytes($key->id(), $message->toArray()));
    return new SignedEnvelope($message->sender, $key->id(), $message->encryption, $message->toArray(), base64_encode($signature));
  }

  /**
   * The exact bytes a signature covers.
   */
  public static function signedBytes(string $key_id, array $message): string {
    return self::CONTEXT . CanonicalJson::encode(['key_id' => $key_id, 'message' => $message]);
  }

}
