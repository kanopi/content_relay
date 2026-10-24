<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Keeps seen message IDs in a table, relying on its primary key for atomicity.
 */
class DatabaseReplayGuard implements ReplayGuardInterface {

  public const TABLE = 'content_relay_seen_message';

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function claim(string $sender, string $message_id, int $expires): bool {
    try {
      $this->database->insert(self::TABLE)
        ->fields(['sender' => $sender, 'message_id' => $message_id, 'expires' => $expires])
        ->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prune(int $now): int {
    return (int) $this->database->delete(self::TABLE)
      ->condition('expires', $now, '<')
      ->execute();
  }

}
