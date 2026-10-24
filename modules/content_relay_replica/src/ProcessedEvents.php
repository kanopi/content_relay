<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Remembers the answer given to each event.
 *
 * An origin that timed out waiting resends the same event. The replica
 * answers with what it answered the first time instead of applying it again.
 * Later phases also read this for the status endpoint, which is how the
 * origin learns the outcome of an event it was told is queued.
 */
class ProcessedEvents {

  public const TABLE = 'content_relay_processed_event';

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * The answer recorded for an event.
   *
   * @return array{http_status: int, result: array}|null
   *   NULL when the event was not processed.
   */
  public function get(string $event_id): ?array {
    $row = $this->database->select(self::TABLE, 'p')->fields('p', ['http_status', 'result'])->condition('event_id', $event_id)->execute()->fetchAssoc();
    return $row ? ['http_status' => (int) $row['http_status'], 'result' => json_decode($row['result'], TRUE) ?: []] : NULL;
  }

  /**
   * Records or updates the answer for an event.
   */
  public function record(string $event_id, string $entity_type_id, string $uuid, int $http_status, array $result): void {
    $now = $this->time->getCurrentTime();
    $fields = ['http_status' => $http_status, 'result' => json_encode($result, \JSON_THROW_ON_ERROR), 'changed' => $now];
    $this->database->merge(self::TABLE)
      ->key('event_id', $event_id)
      ->fields($fields)
      ->insertFields($fields + [
        'event_id' => $event_id,
        'entity_type' => $entity_type_id,
        'uuid' => $uuid,
        'created' => $now,
      ])
      ->execute();
  }

  /**
   * Forgets answers older than the retention period.
   */
  public function prune(): int {
    $days = (int) ($this->configFactory->get('content_relay_replica.settings')->get('processed_retention_days') ?: 60);
    return (int) $this->database->delete(self::TABLE)->condition('changed', $this->time->getCurrentTime() - $days * 86400, '<')->execute();
  }

}
