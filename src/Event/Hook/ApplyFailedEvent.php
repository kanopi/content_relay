<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event\Hook;

use Drupal\Component\EventDispatcher\Event;
use Drupal\content_relay\Event\SyncEvent;

/**
 * Dispatched when a replica could not apply a valid event.
 *
 * Subscribe to send alerts by email, chat or anything else. The failure is
 * already recorded and visible in the replica's report; this event is only a
 * notification.
 */
final class ApplyFailedEvent extends Event {

  /**
   * Constructs an ApplyFailedEvent.
   *
   * @param \Drupal\content_relay\Event\SyncEvent $event
   *   The event that failed.
   * @param string $failureClass
   *   One of "transient", "permanent" or "dependency".
   * @param string $errorCode
   *   A short machine-readable code, such as "validation_failed".
   * @param string $message
   *   A human-readable summary.
   * @param array $violations
   *   Field-level violations: each with field, langcode and message.
   * @param int $attempts
   *   How many times it was attempted.
   */
  public function __construct(
    public readonly SyncEvent $event,
    public readonly string $failureClass,
    public readonly string $errorCode,
    public readonly string $message,
    public readonly array $violations,
    public readonly int $attempts,
  ) {}

}
