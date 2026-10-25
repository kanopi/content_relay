<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\File;

/**
 * A file could not be fetched.
 */
class FileTransportException extends \RuntimeException {

  /**
   * The download link has expired; a fresh one may succeed.
   */
  public const EXPIRED = 'expired';

  /**
   * The origin refused; retrying the same request will not help.
   */
  public const REFUSED = 'refused';

  /**
   * Worth retrying later: timeouts, connection failures, server errors.
   */
  public const TRANSIENT = 'transient';

  public function __construct(
    public readonly string $reason,
    string $message,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}
