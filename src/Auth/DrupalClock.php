<?php

declare(strict_types=1);

namespace Drupal\content_relay\Auth;

use Drupal\Component\Datetime\TimeInterface;
use Psr\Clock\ClockInterface;

/**
 * Drupal's time service as a PSR-20 clock, for the JWT library.
 */
final class DrupalClock implements ClockInterface {

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable())->setTimestamp($this->time->getCurrentTime());
  }

}
