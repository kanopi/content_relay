<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

/**
 * A received message was refused, with a machine-readable reason.
 *
 * The reason is safe to return to the sender; the message is for logs.
 */
final class VerificationException extends \RuntimeException {

  public const MALFORMED = 'malformed';
  public const UNKNOWN_KEY = 'unknown_key';
  public const BAD_SIGNATURE = 'bad_signature';
  public const HEADER_MISMATCH = 'header_mismatch';
  public const WRONG_RECIPIENT = 'wrong_recipient';
  public const STALE = 'stale_timestamp';
  public const REPLAYED = 'replayed';
  public const ENCRYPTION_REQUIRED = 'encryption_required';
  public const UNSUPPORTED_ENCRYPTION = 'unsupported_encryption';

  public function __construct(
    public readonly string $reason,
    string $message,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}
