<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay;

use Drupal\Component\Datetime\Time;

/**
 * A clock a test can move forward, standing in for datetime.time.
 *
 * A kernel test swaps it in from register(), before any service holds the
 * time:
 *
 * @code
 * $container->getDefinition('datetime.time')->setClass(TestClock::class);
 * @endcode
 *
 * Every time it gives is the real one plus the offset, which the test moves
 * with advance(). It lives in a static property, so it is per process.
 */
final class TestClock extends Time {

  /**
   * Seconds added to the real time.
   */
  private static int $offset = 0;

  /**
   * Moves the clock forward.
   */
  public static function advance(int $seconds): void {
    self::$offset += $seconds;
  }

  /**
   * The current time on this clock.
   */
  public static function now(): int {
    return time() + self::$offset;
  }

  /**
   * Puts the clock back to the real time.
   */
  public static function reset(): void {
    self::$offset = 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return parent::getRequestTime() + self::$offset;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime() {
    return parent::getRequestMicroTime() + self::$offset;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime() {
    return parent::getCurrentTime() + self::$offset;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime() {
    return parent::getCurrentMicroTime() + self::$offset;
  }

}
