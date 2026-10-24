<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Asks the delivery queue to visit a replica.
 *
 * Queue items name a replica, not an event: the worker sends whatever is due
 * for it, in order. Duplicate items are harmless, but each replica is queued
 * at most once per request.
 */
class DeliveryScheduler {

  public const QUEUE = 'content_relay_origin_delivery';

  /**
   * Replicas already queued during this request.
   *
   * @var array<string, true>
   */
  protected array $scheduled = [];

  public function __construct(
    protected QueueFactory $queueFactory,
    protected Outbox $outbox,
    protected TimeInterface $time,
  ) {}

  /**
   * Queues a delivery run for replicas.
   *
   * @param string[] $replica_ids
   *   Replica IDs.
   */
  public function schedule(array $replica_ids): void {
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($replica_ids as $id) {
      if (!isset($this->scheduled[$id])) {
        $queue->createItem(['replica' => $id]);
        $this->scheduled[$id] = TRUE;
      }
    }
  }

  /**
   * Queues a run for every replica with events due, such as retries.
   */
  public function scheduleDue(): void {
    // Cron runs once per request: do not let an earlier schedule() call in the
    // same request hide a replica whose retry has since come due.
    $this->scheduled = [];
    $this->schedule($this->outbox->replicasWithDueEvents());
  }

}
