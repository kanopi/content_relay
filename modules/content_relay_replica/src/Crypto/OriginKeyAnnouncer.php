<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Crypto;

use Drupal\content_relay\Key\KeyAnnouncerInterface;
use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\SubscriptionManager;
use Drupal\Core\Session\AccountInterface;

/**
 * Tells the origin about this replica's keys, with a subscription update.
 *
 * The update is signed with the key the origin knows; the origin must have
 * confirmed before a new signing key is used.
 */
class OriginKeyAnnouncer implements KeyAnnouncerInterface {

  public function __construct(
    protected SubscriptionManager $subscription,
    protected OriginPeer $origin,
    protected AccountInterface $currentUser,
    protected ActivePairing $active,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function announce(): array {
    $results = [];
    // Every origin this site receives from.
    foreach ($this->origin->all() as $pairing => $origin) {
      $results[$origin['site_id']] = $this->active->run($pairing, fn () => $this->subscription->propose($this->subscription->getReplicaTerms(), (int) $this->currentUser->id()) !== NULL);
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresAll(): bool {
    return TRUE;
  }

}
