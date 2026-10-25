<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Remembers how far into each origin's event stream this replica is.
 *
 * Every event says which sequence the origin sent before it. When that is
 * not the last one received here, something in between was lost, and the
 * range is recorded for gap filling.
 */
class StreamTracker {

  public const TABLE = 'content_relay_stream';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Records a received event.
   *
   * A gap is stored as [after, missing]: the last sequence received before it,
   * and the sequence of the event known to be lost (the predecessor named by
   * the event that revealed the gap). When that lost event turns up late, its
   * own predecessor either closes the gap or moves it further back.
   *
   * @return bool
   *   TRUE when a gap was detected before this event.
   */
  public function record(string $source_site, int $sequence, ?int $previous): bool {
    $transaction = $this->database->startTransaction();
    $query = $this->database->select(self::TABLE, 't')->fields('t');
    $query->condition('source_site', $source_site);
    $query->forUpdate();
    $row = $query->execute()->fetchAssoc();
    $last = $row ? (int) $row['last_sequence'] : 0;
    $gaps = $row && $row['gaps'] ? json_decode($row['gaps'], TRUE) : [];
    $gap = FALSE;
    if ($sequence > $last) {
      if ($previous !== NULL && $previous > $last) {
        $gaps[] = [$last, $previous];
        $gap = TRUE;
      }
      $last = $sequence;
    }
    else {
      foreach ($gaps as $i => [$after, $missing]) {
        if ($sequence === $missing) {
          if ($previous === NULL || $previous <= $after) {
            unset($gaps[$i]);
          }
          else {
            $gaps[$i] = [$after, $previous];
          }
        }
      }
      $gaps = array_values($gaps);
    }
    $this->database->merge(self::TABLE)
      ->key('source_site', $source_site)
      ->fields(['last_sequence' => $last, 'gaps' => json_encode($gaps), 'changed' => $this->time->getCurrentTime()])
      ->execute();
    unset($transaction);
    return $gap;
  }

  /**
   * The last sequence received from an origin.
   */
  public function getLastSequence(string $source_site): int {
    return (int) $this->database->select(self::TABLE, 't')->fields('t', ['last_sequence'])->condition('source_site', $source_site)->execute()->fetchField();
  }

  /**
   * Recorded gaps, as [after, missing] pairs.
   *
   * @return list<array{0: int, 1: int}>
   *   Ranges.
   */
  public function getGaps(string $source_site): array {
    $gaps = $this->database->select(self::TABLE, 't')->fields('t', ['gaps'])->condition('source_site', $source_site)->execute()->fetchField();
    return $gaps ? json_decode($gaps, TRUE) : [];
  }

  /**
   * Replaces a recorded gap once it has been filled, or partly filled.
   *
   * @param string $source_site
   *   The origin.
   * @param array{0: int, 1: int} $gap
   *   The gap as getGaps() returned it.
   * @param array{0: int, 1: int}|null $remaining
   *   What is left of it, or NULL when it is filled.
   */
  public function replaceGap(string $source_site, array $gap, ?array $remaining = NULL): void {
    $transaction = $this->database->startTransaction();
    $query = $this->database->select(self::TABLE, 't')->fields('t', ['gaps']);
    $query->condition('source_site', $source_site);
    $query->forUpdate();
    $stored = $query->execute()->fetchField();
    $gaps = $stored ? json_decode($stored, TRUE) : [];
    $gaps = array_values(array_filter($gaps, fn (array $g) => $g !== $gap));
    if ($remaining !== NULL) {
      $gaps[] = $remaining;
    }
    $this->database->update(self::TABLE)
      ->fields(['gaps' => json_encode($gaps), 'changed' => $this->time->getCurrentTime()])
      ->condition('source_site', $source_site)
      ->execute();
    unset($transaction);
  }

}
