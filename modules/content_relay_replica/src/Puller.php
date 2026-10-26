<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;

/**
 * Fetches one item from the origin and applies it.
 *
 * The origin builds the item as an event under this site's contract; it goes
 * through the apply service like any other event, except that local edits
 * are never replaced unless the caller accepts the origin's version.
 */
class Puller {

  public function __construct(
    protected OriginClient $originClient,
    protected OriginPeer $origin,
    protected SyncApplier $applier,
    protected ActivePairing $active,
  ) {}

  /**
   * Pulls an item.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $uuid
   *   The UUID.
   * @param bool $force
   *   Apply even if this site has that revision already, as after a mapping
   *   change.
   * @param bool $accept
   *   Replace local edits with the origin's version. Without it, an item
   *   edited here is left as it is and marked as in conflict.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the origin cannot be asked or does not give the item.
   */
  public function pull(string $entity_type_id, string $uuid, bool $force = FALSE, bool $accept = FALSE): ApplyResult {
    // Done with the origin the item came from.
    if (($pairing = $this->active->switchFor($entity_type_id, $uuid)) !== NULL) {
      return $this->active->run($pairing, fn () => $this->pull($entity_type_id, $uuid, $force, $accept));
    }
    $event = $this->originClient->item($entity_type_id, $uuid);
    return $this->applier->apply($event, (string) $this->origin->getSiteId(), FALSE, $force || $accept, $accept ? SyncApplier::CONFLICT_ACCEPT : SyncApplier::CONFLICT_REJECT);
  }

}
