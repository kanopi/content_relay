<?php

declare(strict_types=1);

namespace Drupal\content_relay\Auth;

/**
 * A token was refused. The reason is safe to return to the caller.
 */
final class AuthenticationException extends \RuntimeException {

  public function __construct(
    public readonly string $reason,
    string $message,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

}
