<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Crypto;

use Drupal\content_relay\Key\KeyAnnouncerInterface;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Session\AccountInterface;

/**
 * Tells replicas about this origin's keys, with a new contract version each.
 *
 * The contract lists the origin's public keys. A replica that does not
 * confirm keeps being sent messages signed with a key it already trusts, so
 * a rotation does not wait for replicas that are offline.
 */
class ReplicaKeyAnnouncer implements KeyAnnouncerInterface {

  public function __construct(
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function announce(): array {
    $results = [];
    foreach ($this->registry->getAll() as $replica) {
      if (!$replica->receivesEvents() || $this->contracts->getLatest($replica->id) === NULL) {
        continue;
      }
      $this->contracts->propose($replica->id, NULL, NULL, 'origin', (int) $this->currentUser->id());
      $results[$replica->id] = $this->contracts->push($replica->id, TRUE);
    }
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresAll(): bool {
    return FALSE;
  }

}
