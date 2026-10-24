<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Apply;

use Drupal\Core\Lock\LockAcquiringException;

/**
 * Decides whether an exception during apply is worth retrying.
 *
 * Transient means the same event may well succeed in a minute: the database
 * was busy or briefly unavailable. Everything else is permanent, because a
 * validation error, a schema mismatch or a bug in a hook will fail the same
 * way every time until someone changes something.
 */
class FailureClassifier {

  /**
   * SQLSTATE codes that mean "try again".
   */
  protected const TRANSIENT_SQLSTATES = [
    // Serialization failure or deadlock.
    '40001',
    '40P01',
    // Connection failures.
    '08000',
    '08001',
    '08003',
    '08006',
    '08S01',
    // Lock wait timeout (PostgreSQL).
    '55P03',
  ];

  /**
   * MySQL error numbers that mean "try again".
   */
  protected const TRANSIENT_MYSQL_ERRORS = [
    // Lock wait timeout exceeded.
    1205,
    // Deadlock found.
    1213,
    // Server has gone away; lost connection during query.
    2006,
    2013,
  ];

  /**
   * Whether an exception, or any it wraps, is transient.
   */
  public function isTransient(\Throwable $e): bool {
    for ($current = $e; $current !== NULL; $current = $current->getPrevious()) {
      if ($current instanceof LockAcquiringException || $current instanceof TransientApplyException) {
        return TRUE;
      }
      if ($current instanceof \PDOException) {
        $info = $current->errorInfo ?? [];
        if (in_array((string) ($info[0] ?? $current->getCode()), self::TRANSIENT_SQLSTATES, TRUE) || in_array((int) ($info[1] ?? 0), self::TRANSIENT_MYSQL_ERRORS, TRUE)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
