<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

/**
 * A pairing request was refused, with a reason the replica may be told.
 */
final class PairingException extends \RuntimeException {

  public function __construct(
    public readonly string $reason,
    string $message,
    public readonly int $httpStatus = 403,
  ) {
    parent::__construct($message);
  }

}
