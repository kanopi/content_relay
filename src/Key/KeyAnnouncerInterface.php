<?php

declare(strict_types=1);

namespace Drupal\content_relay\Key;

/**
 * Tells this site's peers about its current public keys.
 *
 * Each role module provides one, tagged content_relay.key_announcer: the
 * origin through a new contract version for each replica, a replica through a
 * subscription update to its origin. A site with both roles has both.
 */
interface KeyAnnouncerInterface {

  /**
   * Sends the current public keys to every peer.
   *
   * @return array<string, bool>
   *   By peer site ID, whether it confirmed.
   */
  public function announce(): array;

  /**
   * Whether every peer must confirm before a new key is used.
   *
   * A replica's origin must know a new signing key before the replica signs
   * with it. The origin instead keeps signing for each replica with a key
   * that replica has confirmed, so it need not wait for all of them.
   */
  public function requiresAll(): bool;

}
