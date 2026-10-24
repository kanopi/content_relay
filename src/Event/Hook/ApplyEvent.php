<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event\Hook;

use Drupal\Component\EventDispatcher\Event;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Dispatched around applying an event on a replica.
 *
 * Both dispatches happen inside the apply transaction: a subscriber that throws
 * rolls the whole apply back, and the event is recorded as failed. For a
 * delete, the entity is the one being deleted.
 */
final class ApplyEvent extends Event {

  public function __construct(
    public readonly SyncEvent $event,
    public readonly ContentEntityInterface $entity,
  ) {}

}
