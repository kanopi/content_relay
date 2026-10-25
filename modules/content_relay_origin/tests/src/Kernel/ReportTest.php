<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\OriginReconciler;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\ReplicaItems;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\content_relay_origin\Report\ReportRebuild;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the status report's table and bulk push.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReportTest extends OriginTestBase {

  /**
   * Report rows for an item, by replica.
   */
  private function rows(NodeInterface $node): array {
    $rows = $this->container->get('database')->select(ReportIndex::TABLE, 'r')
      ->fields('r')
      ->condition('uuid', $node->uuid())
      ->execute()
      ->fetchAllAssoc('replica_id');
    return array_map(fn (object $row) => (array) $row, $rows);
  }

  /**
   * The item's status on the fake replica.
   */
  private function itemStatus(NodeInterface $node): ?string {
    return $this->rows($node)[$this->replica->siteId]['status'] ?? NULL;
  }

  /**
   * Runs the report rebuild queue.
   */
  private function runRebuildQueue(): void {
    $queue = $this->container->get('queue')->get(ReportRebuild::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(ReportRebuild::QUEUE);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * Items saved before pairing show as never sent once the report rebuilds.
   */
  public function testNeverSentAfterRebuild(): void {
    $old = $this->node('article', 'Before pairing');
    $this->node('page', 'Not subscribed');
    $this->assertSame([], $this->rows($old));
    $this->pairAndAcknowledge();

    $rebuild = $this->container->get('content_relay_origin.report_rebuild');
    $this->assertTrue($rebuild->rebuildIfChanged());
    $this->assertFalse($rebuild->rebuildIfChanged(), 'Nothing changed since.');
    $this->runRebuildQueue();

    $row = $this->rows($old)[$this->replica->siteId];
    $this->assertSame(ReportIndex::NEVER_SENT, $row['status']);
    $this->assertSame(ReportIndex::TRANSLATION_NEVER_SENT, $row['translation_status']);
    $this->assertSame('Before pairing', $row['label']);
    $this->assertSame((string) $old->id(), $row['entity_id']);
    $this->assertSame('1', $row['item_out_of_sync']);
    // Only bundles in the replica's contract.
    $this->assertSame(1, (int) $this->container->get('database')->select(ReportIndex::TABLE)->countQuery()->execute()->fetchField());
  }

  /**
   * The status follows the event log and the replica's answers.
   */
  public function testStatuses(): void {
    $this->pairAndAcknowledge();
    $node = $this->node('article', 'Statuses');
    $this->assertSame(ReportIndex::PENDING, $this->itemStatus($node));
    $this->deliver();
    $row = $this->rows($node)[$this->replica->siteId];
    $this->assertSame(ReportIndex::IN_SYNC, $row['status']);
    $this->assertSame($row['origin_hash'], $row['replica_hash']);
    $this->assertNotNull($row['last_success']);
    $this->assertSame('0', $row['item_out_of_sync']);

    // The replica's copy differs from what the origin has now.
    $this->container->get('database')->update(ReplicaItems::TABLE)
      ->fields(['translation_hashes' => json_encode(['und' => str_repeat('0', 64)])])
      ->execute();
    $this->container->get('content_relay_origin.report')->refresh('node', $node->uuid());
    $this->assertSame(ReportIndex::OUT_OF_SYNC, $this->itemStatus($node));

    $responses = [
      [500, ['result' => 'error'], ReportIndex::FAILED],
      [400, ['result' => 'rejected'], ReportIndex::DEAD_LETTERED],
      [422, ['result' => 'apply_failed'], ReportIndex::FAILED_ON_REPLICA],
      [202, ['result' => 'accepted'], ReportIndex::ACCEPTED],
      [409, ['result' => 'conflict'], ReportIndex::CONFLICT],
    ];
    foreach ($responses as $i => [$code, $body, $expected]) {
      // Each failure must not hold back the next attempt.
      $this->container->get('database')->update(Outbox::TABLE)->fields(['status' => 'dead_letter'])->condition('status', [
        'queued',
        'failed',
      ], 'IN')->execute();
      $this->replica->eventResponses[] = [$code, $body];
      $node->setTitle("Change $i")->save();
      $this->deliver();
      $this->assertSame($expected, $this->itemStatus($node), "HTTP $code");
    }
    $this->assertNotNull($this->rows($node)[$this->replica->siteId]['last_error']);

    $control = $this->container->get('content_relay_origin.item_sync_control');
    $control->markNotSyncable($node);
    $row = $this->rows($node)[$this->replica->siteId];
    $this->assertSame(ReportIndex::NOT_SYNCABLE, $row['status']);
    $this->assertSame('1', $row['not_syncable']);
    $this->assertNotNull($row['detached_at']);

    $node->delete();
    $this->assertSame([], $this->rows($node));
  }

  /**
   * Bulk push queues exactly the selected items, skipping blocked ones.
   */
  public function testBulkPush(): void {
    $this->pairAndAcknowledge();
    $nodes = [];
    foreach (['One', 'Two', 'Three', 'Four'] as $title) {
      $nodes[$title] = $this->node('article', $title);
    }
    $this->deliver();
    $control = $this->container->get('content_relay_origin.item_sync_control');
    $control->markNotSyncable($nodes['Three']);
    $control->replicaDetached($this->replica->siteId, 'node', $nodes['Four']->uuid(), 'article');
    $this->deliver();
    $before = count($this->replica->events);

    $ids = array_map(fn (NodeInterface $node) => (int) $this->rows($node)[$this->replica->siteId]['id'], [
      $nodes['One'],
      $nodes['Three'],
      $nodes['Four'],
    ]);
    $bulk = $this->container->get('content_relay_origin.bulk_push');
    $plan = $bulk->plan($ids);
    $this->assertCount(1, $plan['items']);
    $this->assertSame($nodes['One']->uuid(), $plan['items'][0]['uuid']);
    $this->assertSame([ReportIndex::NOT_SYNCABLE => 1, ReportIndex::DETACHED_BY_REPLICA => 1], $plan['skipped'][$this->replica->siteId]);
    $this->assertSame([$this->replica->siteId => 1], $bulk::countByReplica($plan['items']));

    // Languages the rows are not in select nothing.
    $this->assertSame([], $bulk->plan($ids, ['fr'])['items']);

    $context = [];
    $bulk::batchStep($plan['items'], $context);
    $this->assertSame(['queued' => 1], $context['results'][$this->replica->siteId]);
    $this->deliver();
    $this->assertCount($before + 1, $this->replica->events);
    $this->assertSame('One', $this->replica->events[$before]['snapshot']['shared']['title'][0]['value']);
    $reasons = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['reason'])->condition('reason', Outbox::REASON_BULK)->execute()->fetchCol();
    $this->assertCount(1, $reasons);

    $this->assertSame(ReportIndex::IN_SYNC, $this->itemStatus($nodes['One']));
    $summary = (string) $bulk::summarize($context['results'])[0];
    $this->assertStringContainsString('1 queued', $summary);
  }

  /**
   * Reconciliation re-sends what a replica lacks, a limited number at once.
   */
  public function testReconcile(): void {
    $nodes = [];
    foreach (['One', 'Two', 'Three', 'Four'] as $title) {
      $nodes[] = $this->node('article', $title);
    }
    $this->pairAndAcknowledge();
    $this->container->get('content_relay_origin.report_rebuild')->rebuildIfChanged();
    $this->runRebuildQueue();
    $this->assertSame(ReportIndex::NEVER_SENT, $this->itemStatus($nodes[0]));
    $this->config('content_relay_origin.settings')->set('reconcile.max_items', 1)->save();
    $reconciler = $this->container->get('content_relay_origin.reconciler');

    $result = $reconciler->run('manual');
    $this->assertSame([$this->replica->siteId => ['queued' => 1]], $result['sent']);
    $this->assertSame(3, $result['queued']);
    // Not again while the last run's queue has items.
    $this->assertSame(3, $reconciler->run('manual')['busy']);

    // The worker sends as many per cron run as the limit allows.
    $queue = $this->container->get('queue')->get(OriginReconciler::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(OriginReconciler::QUEUE);
    $processed = 0;
    try {
      while (is_object($item = $queue->claimItem())) {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
    }
    catch (SuspendQueueException) {
      $queue->releaseItem($item);
    }
    $this->assertSame(1, $processed);
    $this->assertSame(2, $queue->numberOfItems());

    $this->deliver();
    $this->assertCount(2, $this->replica->events);
    $reasons = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['reason'])->condition('reason', Outbox::REASON_RECONCILE)->execute()->fetchCol();
    $this->assertCount(2, $reasons);
    $this->assertSame(ReportIndex::IN_SYNC, $this->itemStatus($nodes[0]));
  }

}
