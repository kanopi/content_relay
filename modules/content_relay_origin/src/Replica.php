<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

/**
 * A replica as the origin knows it.
 */
final class Replica {

  // Receives events.
  public const ACTIVE = 'active';
  // Paired, waiting for its first contract to be acknowledged or for an
  // administrator's approval. Events are queued, not sent.
  public const PENDING = 'pending';
  // Temporarily stopped by the origin. Events are queued, not sent.
  public const SUSPENDED = 'suspended';
  // Permanently cut off. Nothing is queued or accepted.
  public const REVOKED = 'revoked';

  /**
   * Constructs a Replica.
   *
   * @param string $id
   *   The replica's site ID.
   * @param string $label
   *   A name for administrators.
   * @param string $endpoint
   *   The HTTPS URL events are posted to.
   * @param string $status
   *   One of the class constants.
   * @param array<string, array{public_key: string, status: string}> $signingKeys
   *   The replica's Ed25519 public keys (base64), by key ID.
   * @param array<string, array{public_key: string, status: string}> $encryptionKeys
   *   The replica's X25519 public keys (base64), by key ID.
   * @param int $contractVersion
   *   The acknowledged contract version in force, 0 before the first.
   * @param int|null $lastSuccess
   *   When a delivery last succeeded.
   * @param bool $approved
   *   Whether an administrator approved it, where approval is required.
   * @param string[] $allowedIps
   *   Addresses and ranges it may connect from; empty for any.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $label,
    public readonly string $endpoint,
    public readonly string $status,
    public readonly array $signingKeys,
    public readonly array $encryptionKeys = [],
    public readonly int $contractVersion = 0,
    public readonly ?int $lastSuccess = NULL,
    public readonly bool $approved = FALSE,
    public readonly array $allowedIps = [],
  ) {
    if (!str_starts_with(strtolower($endpoint), 'https://')) {
      throw new \InvalidArgumentException('Replica endpoints must use HTTPS.');
    }
    if (!in_array($status, [self::ACTIVE, self::PENDING, self::SUSPENDED, self::REVOKED], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown replica status "%s".', $status));
    }
  }

  /**
   * Whether events are delivered to this replica now.
   */
  public function receivesDeliveries(): bool {
    return $this->status === self::ACTIVE && $this->contractVersion > 0;
  }

  /**
   * Whether events are recorded for this replica, to send now or later.
   */
  public function receivesEvents(): bool {
    return $this->status !== self::REVOKED;
  }

}
