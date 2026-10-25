<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event\Hook;

use Drupal\Component\EventDispatcher\Event;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Dispatched when a replica item edited locally receives the origin's update.
 *
 * Subscribers can notify someone, or change the resolution: "overwrite" saves
 * the origin's version as a new revision and flags the item for review;
 * "reject" leaves the local copy and marks the item as in conflict.
 */
final class ConflictEvent extends Event {

  /**
   * Constructs a ConflictEvent.
   *
   * @param \Drupal\content_relay\Event\SyncEvent $event
   *   The origin's event.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The local copy, with its edits.
   * @param string[] $editedTranslations
   *   Langcodes whose content was edited here; empty when only the set of
   *   translations changed.
   * @param string $resolution
   *   What the replica will do: "overwrite" or "reject".
   */
  public function __construct(
    public readonly SyncEvent $event,
    public readonly ContentEntityInterface $entity,
    public readonly array $editedTranslations,
    public string $resolution,
  ) {}

}
