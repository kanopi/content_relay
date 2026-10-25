<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Re-sends to each replica what the status report says it is missing.
 *
 * Items out of sync, never sent, failed or dead-lettered for a replica are
 * pushed again, as a bulk push would. At most reconcile.max_items are
 * recorded for sending in the run itself; the rest go into a queue, which
 * cron works through at the same rate. A new run does not start while the
 * queue from the last one still has items, so nothing is queued twice.
 *
 * Items in flight (pending, accepted), in conflict, not syncable or detached
 * by the replica are left alone, as in a bulk push. The replica's own
 * reconciliation is the other half: it pulls what differs, and deletes what
 * the origin no longer has.
 */
class OriginReconciler {

  public const QUEUE = 'content_relay_origin_reconcile';

  /**
   * Report statuses that call for sending the item again.
   */
  public const RESEND = [
    ReportIndex::OUT_OF_SYNC,
    ReportIndex::NEVER_SENT,
    ReportIndex::FAILED,
    ReportIndex::DEAD_LETTERED,
  ];

  public function __construct(
    protected Connection $database,
    protected ReportIndex $report,
    protected BulkPush $bulkPush,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Re-sends what replicas are missing.
   *
   * @param string $trigger
   *   What started it: cron, manual or drush.
   * @param string|null $replica_id
   *   One replica, or NULL for all.
   *
   * @return array{busy: int, sent: array<string, array<string, int>>, queued: int}
   *   Items still queued from the last run (when the run did not start),
   *   outcomes of what was recorded for sending by replica, and how many
   *   were queued for later.
   */
  public function run(string $trigger, ?string $replica_id = NULL): array {
    $queue = $this->queueFactory->get(self::QUEUE);
    $waiting = $queue->numberOfItems();
    if ($waiting > 0) {
      return ['busy' => $waiting, 'sent' => [], 'queued' => 0];
    }
    $filters = ['status' => self::RESEND];
    if ($replica_id !== NULL) {
      $filters['replica_id'] = $replica_id;
    }
    $items = $this->bulkPush->plan($this->report->rowIds($filters))['items'];
    $now = array_slice($items, 0, $this->maxItems());
    $later = array_slice($items, count($now));
    $sent = $now === [] ? [] : $this->bulkPush->queueMultiple($now, Outbox::REASON_RECONCILE);
    foreach ($later as $item) {
      $queue->createItem($item);
    }
    $this->state->set('content_relay_origin.reconciled', $this->time->getCurrentTime());
    $this->logger->notice('Reconciliation (@trigger) re-sent @now item(s) and queued @later: @sent.', [
      '@trigger' => $trigger,
      '@now' => count($now),
      '@later' => count($later),
      '@sent' => json_encode($sent),
    ]);
    return ['busy' => 0, 'sent' => $sent, 'queued' => count($later)];
  }

  /**
   * Runs when due. For cron.
   */
  public function cron(): void {
    $interval = (int) ($this->configFactory->get('content_relay_origin.settings')->get('reconcile.interval') ?? 0);
    if ($interval > 0 && $this->time->getCurrentTime() - (int) $this->state->get('content_relay_origin.reconciled', 0) >= $interval) {
      $this->run('cron');
    }
  }

  /**
   * Re-sends one queued item, if the replica still needs it.
   *
   * @return string
   *   The outcome: queued, not_needed, or why it could not be sent.
   */
  public function processQueued(array $item): string {
    $needed = $this->database->select(ReportIndex::TABLE, 'r')
      ->condition('entity_type', $item['entity_type'])
      ->condition('uuid', $item['uuid'])
      ->condition('replica_id', $item['replica_id'])
      ->condition('status', self::RESEND, 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    if (!$needed) {
      return 'not_needed';
    }
    $outcomes = $this->bulkPush->queueMultiple([$item], Outbox::REASON_RECONCILE);
    return (string) (array_key_first($outcomes[$item['replica_id']] ?? []) ?? 'not_in_contract');
  }

  /**
   * Items re-sent per run, and per cron run from the queue.
   */
  public function maxItems(): int {
    return max(1, (int) ($this->configFactory->get('content_relay_origin.settings')->get('reconcile.max_items') ?? 200));
  }

}
