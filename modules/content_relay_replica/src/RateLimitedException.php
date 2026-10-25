<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

/**
 * The origin will not answer more reads from this site for now.
 */
final class RateLimitedException extends SubscriptionException {

  public function __construct(
    public readonly int $retryAfter,
  ) {
    parent::__construct(sprintf('The origin asks this site to wait %d second(s) before reading more.', $retryAfter));
  }

}
