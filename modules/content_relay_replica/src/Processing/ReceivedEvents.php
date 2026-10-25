<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Processing;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;

/**
 * Events accepted to apply later, in queued processing.
 *
 * The full event is kept here, already verified, and a queue item names it.
 * The row goes once the event has been applied, when the apply service has
 * recorded its answer in ProcessedEvents. A duplicate delivery of an event
 * still waiting here is accepted again without a second row.
 */
class ReceivedEvents {

  public const TABLE = 'content_relay_received_event';
  public const QUEUE = 'content_relay_replica_inbox';

  public function __construct(
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Keeps an event and queues it.
   *
   * @return bool
   *   FALSE when it was already waiting.
   */
  public function add(SyncEvent $event, string $source_site): bool {
    if ($this->has($event->eventId)) {
      return FALSE;
    }
    $this->database->insert(self::TABLE)->fields([
      'event_id' => $event->eventId,
      'source_site' => $source_site,
      'entity_type' => $event->entityType,
      'uuid' => $event->entityUuid,
      'sequence' => $event->sequence,
      'payload' => json_encode($event->toArray(), \JSON_THROW_ON_ERROR),
      'received' => $this->time->getCurrentTime(),
    ])->execute();
    $this->queueFactory->get(self::QUEUE)->createItem(['event_id' => $event->eventId]);
    return TRUE;
  }

  /**
   * Whether an event is waiting.
   */
  public function has(string $event_id): bool {
    return (bool) $this->database->select(self::TABLE, 'r')->condition('event_id', $event_id)->countQuery()->execute()->fetchField();
  }

  /**
   * A waiting event and its sender.
   *
   * @return array{event: \Drupal\content_relay\Event\SyncEvent, source_site: string}|null
   *   NULL when it is not waiting.
   */
  public function get(string $event_id): ?array {
    $row = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['source_site', 'payload'])
      ->condition('event_id', $event_id)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    return ['event' => SyncEvent::fromArray(json_decode($row['payload'], TRUE)), 'source_site' => $row['source_site']];
  }

  /**
   * Removes an applied event.
   */
  public function remove(string $event_id): void {
    $this->database->delete(self::TABLE)->condition('event_id', $event_id)->execute();
  }

  /**
   * How many events are waiting.
   */
  public function backlog(): int {
    return (int) $this->database->select(self::TABLE)->countQuery()->execute()->fetchField();
  }

  /**
   * Queues waiting events again when their queue items were lost.
   *
   * Only when the queue is empty: with items in it, the events are still on
   * their way.
   *
   * @return int
   *   How many were queued.
   */
  public function requeueStale(int $older_than): int {
    $queue = $this->queueFactory->get(self::QUEUE);
    if ($queue->numberOfItems() > 0) {
      return 0;
    }
    $ids = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['event_id'])
      ->condition('received', $this->time->getCurrentTime() - $older_than, '<')
      ->orderBy('sequence')
      ->execute()
      ->fetchCol();
    foreach ($ids as $event_id) {
      $queue->createItem(['event_id' => $event_id]);
    }
    return count($ids);
  }

}
