<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

/**
 * Remembers message IDs so that each is accepted only once.
 */
interface ReplayGuardInterface {

  /**
   * Records a message ID, unless it was already recorded.
   *
   * Must be atomic: two requests carrying the same ID at the same moment must
   * not both succeed.
   *
   * @param string $sender
   *   The sender's site ID.
   * @param string $message_id
   *   The message ID.
   * @param int $expires
   *   When the record may be forgotten. After this the message would be
   *   refused as stale anyway.
   *
   * @return bool
   *   TRUE if this is the first time, FALSE for a replay.
   */
  public function claim(string $sender, string $message_id, int $expires): bool;

  /**
   * Forgets records that have expired.
   *
   * @return int
   *   How many were removed.
   */
  public function prune(int $now): int;

}
