<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\Component\Uuid\Uuid;

/**
 * Something one site says to another, before signing.
 *
 * Everything here is covered by the signature, including who it is for and
 * whether it was meant to be encrypted, so neither can be changed in transit.
 *
 * The message ID is unique per delivery attempt and is what replay protection
 * remembers. It is deliberately not the sync event ID: an origin retrying an
 * event after a timeout sends a new message carrying the same event, and the
 * replica must be able to accept it and answer from its earlier result. Event
 * level duplicates are the apply service's concern, not the verifier's.
 */
final class Message {

  public const SCHEMA_VERSION = 1;

  /**
   * Constructs a Message.
   *
   * @param string $id
   *   UUID, unique per delivery attempt.
   * @param string $type
   *   What the body is, for example "event", "contract" or "detach_notice".
   * @param string $sender
   *   Site ID of the sender.
   * @param string $recipient
   *   Site ID of the one site that may accept it.
   * @param int $timestamp
   *   When it was signed, as a Unix timestamp.
   * @param \Drupal\content_relay\Crypto\EncryptionMode $encryption
   *   How it is meant to travel.
   * @param array $body
   *   The payload.
   * @param int|null $sequence
   *   Position in the sender's stream to this recipient, for events.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $type,
    public readonly string $sender,
    public readonly string $recipient,
    public readonly int $timestamp,
    public readonly EncryptionMode $encryption,
    public readonly array $body,
    public readonly ?int $sequence = NULL,
  ) {
    if (!Uuid::isValid($id) || $type === '' || $sender === '' || $recipient === '') {
      throw new \InvalidArgumentException('A message needs a UUID, a type, a sender and a recipient.');
    }
  }

  /**
   * Returns the message as the array that is signed.
   */
  public function toArray(): array {
    return [
      'v' => self::SCHEMA_VERSION,
      'id' => $this->id,
      'type' => $this->type,
      'sender' => $this->sender,
      'recipient' => $this->recipient,
      'timestamp' => $this->timestamp,
      'sequence' => $this->sequence,
      'encryption' => $this->encryption->value,
      'body' => $this->body,
    ];
  }

  /**
   * Reads a message from its array form.
   *
   * @throws \InvalidArgumentException
   *   When the data is malformed.
   */
  public static function fromArray(array $data): self {
    if (($data['v'] ?? NULL) !== self::SCHEMA_VERSION) {
      throw new \InvalidArgumentException('Unsupported message version.');
    }
    foreach (['id', 'type', 'sender', 'recipient', 'encryption'] as $key) {
      if (!is_string($data[$key] ?? NULL)) {
        throw new \InvalidArgumentException(sprintf('Message field "%s" must be a string.', $key));
      }
    }
    if (!is_int($data['timestamp'] ?? NULL) || !is_array($data['body'] ?? NULL) || !(is_int($data['sequence'] ?? NULL) || ($data['sequence'] ?? NULL) === NULL)) {
      throw new \InvalidArgumentException('Message timestamp, sequence or body is malformed.');
    }
    return new self(
      $data['id'],
      $data['type'],
      $data['sender'],
      $data['recipient'],
      $data['timestamp'],
      EncryptionMode::tryFrom($data['encryption']) ?? throw new \InvalidArgumentException('Unknown encryption mode.'),
      $data['body'],
      $data['sequence'],
    );
  }

}
