<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event\Hook;

use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched before and after an entity is normalized into a snapshot.
 *
 * Before: subscribers may adjust the context, or the entity in memory (it is
 * never saved). After: subscribers may alter the snapshot. Anything changed
 * after serializing is hashed, so it must be deterministic.
 *
 * @see \Drupal\content_relay\Event\Hook\ContentRelayEvents
 */
final class SerializeEvent extends Event {

  public function __construct(
    public readonly ContentEntityInterface $entity,
    public readonly NormalizationContext $context,
    public ?array $snapshot = NULL,
  ) {}

}
