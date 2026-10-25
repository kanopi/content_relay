<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests reconciliation and gap filling against the fake origin.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReconcileTest extends ReplicaTestBase {

  /**
   * The service under test.
   */
  private Reconciler $reconciler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->useFakeOrigin();
    $this->reconciler = $this->container->get('content_relay_replica.reconciler');
    $this->onOrigin([]);
  }

  /**
   * Sets what the origin lists for articles; tags are always empty.
   *
   * @param list<array{0: array, 1: int}> $items
   *   Snapshots and their revisions.
   * @param string[] $not_syncable
   *   UUIDs the origin keeps from this site.
   */
  private function onOrigin(array $items, array $not_syncable = []): void {
    $hasher = $this->container->get('content_relay.revision_hasher');
    $entries = [];
    foreach ($items as [$snapshot, $revision]) {
      $entries[] = [
        'uuid' => $snapshot['uuid'],
        'revision' => $revision,
        'changed' => 1700000000 + $revision,
        'hash' => $hasher->hash($snapshot)->toArray(),
        'not_syncable' => in_array($snapshot['uuid'], $not_syncable, TRUE),
      ];
    }
    $this->updateFakeOrigin(function (array &$config) use ($entries): void {
      $config['hashes'] = ['node:article' => $entries, 'taxonomy_term:tags' => []];
    });
  }

  /**
   * Applies an article as received from the origin.
   */
  private function synced(string $title): array {
    $snapshot = $this->originArticle($title);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    return $snapshot;
  }

  /**
   * The sync state of an item.
   */
  private function state(string $uuid): ?array {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid);
  }

  /**
   * Lost deletes are deleted here; local and detached content is not.
   */
  public function testDeletedOnOrigin(): void {
    $kept = $this->synced('Kept');
    $deleted = $this->synced('Deleted on origin');
    $detached = $this->synced('Detached here');
    $this->container->get('content_relay_replica.sync_state')->save('node', $detached['uuid'], [
      'detached_at' => 1700000000,
      'status' => SyncStatus::DETACHED_BY_REPLICA,
    ]);
    $local = Node::create(['type' => 'article', 'title' => 'Made here']);
    $local->save();
    $this->onOrigin([[$kept, 1]]);

    $run = $this->reconciler->run('manual');
    $this->assertSame('completed', $run['status']);
    $this->assertSame(1, $run['counts']['deleted']);
    $this->assertNull($this->local($deleted['uuid']));
    $this->assertNull($this->state($deleted['uuid']));
    $this->assertSame('Kept', $this->local($kept['uuid'])->label());
    $this->assertSame('Detached here', $this->local($detached['uuid'])->label());
    $this->assertNotNull(Node::load($local->id()));

    // With "follow deletes", the detached item goes too.
    $this->config('content_relay_replica.settings')->set('detach.follow_deletes', TRUE)->save();
    $this->reconciler->run('manual');
    $this->assertNull($this->local($detached['uuid']));
  }

  /**
   * Lost updates are pulled; detached items are not.
   */
  public function testUpdatedOnOrigin(): void {
    $changed = $this->synced('Before');
    $detached = $this->synced('Detached before');
    $this->container->get('content_relay_replica.sync_state')->save('node', $detached['uuid'], [
      'detached_at' => 1700000000,
      'status' => SyncStatus::DETACHED_BY_REPLICA,
    ]);
    $updated = $this->retitle($changed, 'After');
    $this->serveItem($updated, 2);
    $this->serveItem($this->retitle($detached, 'Detached after'), 2);
    // Created on the origin while this site missed it.
    $missed = $this->originArticle('Missed');
    $this->serveItem($missed, 1);
    $this->onOrigin([
      [$updated, 2],
      [$this->retitle($detached, 'Detached after'), 2],
      [$missed, 1],
    ]);

    $run = $this->reconciler->run('manual');
    $this->assertSame(2, $run['counts']['pulled'], json_encode($run));
    $this->assertSame(1, $run['counts']['skipped_detached']);
    $this->assertSame('After', $this->local($changed['uuid'])->label());
    $this->assertSame('Missed', $this->local($missed['uuid'])->label());
    $this->assertSame('Detached before', $this->local($detached['uuid'])->label());
  }

  /**
   * Running again when in sync changes nothing.
   */
  public function testRepeatChangesNothing(): void {
    $one = $this->synced('One');
    $two = $this->synced('Two');
    $this->onOrigin([[$one, 1], [$two, 1]]);
    $revisions = count($this->revisions($this->local($one['uuid'])));

    foreach ([1, 2] as $attempt) {
      $run = $this->reconciler->run('manual');
      $this->assertSame(['checked' => 2, 'in_sync' => 2], $run['counts'], "Run $attempt");
    }
    $this->assertCount($revisions, $this->revisions($this->local($one['uuid'])));
  }

  /**
   * Items the origin now keeps from this site are marked, not deleted.
   */
  public function testNotSyncableOnOrigin(): void {
    $item = $this->synced('Kept from us');
    $this->onOrigin([[$this->retitle($item, 'Changed'), 2]], [$item['uuid']]);
    $run = $this->reconciler->run('manual');
    $this->assertSame(1, $run['counts']['detached_by_origin']);
    $this->assertSame(SyncStatus::DETACHED_BY_ORIGIN, $this->state($item['uuid'])['status']);
    $this->assertSame('Kept from us', $this->local($item['uuid'])->label());
  }

  /**
   * A different contract version on the origin is reported.
   */
  public function testContractMismatch(): void {
    $this->updateFakeOrigin(fn (array &$config) => $config['hashes_contract_version'] = 2);
    $run = $this->reconciler->run('drush');
    $this->assertSame(1, $run['counts']['contract_mismatch']);
    $this->assertStringContainsString('contract version 2', $run['messages'][0]);
  }

  /**
   * A bundle the origin cannot list deletes nothing.
   */
  public function testFailedListingDeletesNothing(): void {
    $item = $this->synced('Safe');
    $this->updateFakeOrigin(fn (array &$config) => $config['hashes'] = ['taxonomy_term:tags' => []]);
    $run = $this->reconciler->run('manual');
    $this->assertSame(1, $run['counts']['failed_bundles']);
    $this->assertNotNull($this->local($item['uuid']));
  }

  /**
   * Gaps in the stream are filled from what the origin says now.
   */
  public function testFillGaps(): void {
    $updated = $this->synced('Before');
    $gone = $this->synced('Gone');
    $tracker = $this->container->get('content_relay_replica.stream');
    $tracker->record(FakeOrigin::SITE_ID, 1, NULL);
    // Event 5 says it came after 4: 2 to 4 were lost.
    $this->assertTrue($tracker->record(FakeOrigin::SITE_ID, 5, 4));
    $this->serveItem($this->retitle($updated, 'After'), 2);
    $this->updateFakeOrigin(function (array &$config) use ($updated, $gone): void {
      $config['stream'] = [
        2 => [
          'sequence' => 2,
          'operation' => 'update',
          'entity_type' => 'node',
          'bundle' => 'article',
          'uuid' => $updated['uuid'],
          'current' => 'exists',
        ],
        4 => [
          'sequence' => 4,
          'operation' => 'delete',
          'entity_type' => 'node',
          'bundle' => 'article',
          'uuid' => $gone['uuid'],
          'current' => 'gone',
        ],
      ];
    });

    $counts = $this->reconciler->fillGaps();
    $this->assertSame(['pulled' => 1, 'deleted' => 1], $counts);
    $this->assertSame('After', $this->local($updated['uuid'])->label());
    $this->assertNull($this->local($gone['uuid']));
    $this->assertSame([], $tracker->getGaps(FakeOrigin::SITE_ID));
  }

  /**
   * Validation recomputes hashes here and asks the origin.
   */
  public function testValidate(): void {
    $item = $this->synced('Valid');
    $this->serveItem($item, 1);
    $validator = $this->container->get('content_relay_replica.validator');
    $result = $validator->validate('node', $item['uuid']);
    $this->assertTrue($result['local']);
    $this->assertTrue($result['origin']);

    $this->serveItem($this->retitle($item, 'Newer'), 2);
    $this->local($item['uuid'])->setTitle('Changed here')->save();
    $result = $validator->validate('node', $item['uuid']);
    $this->assertFalse($result['local']);
    $this->assertFalse($result['origin']);
    $this->assertSame(2, $result['origin_revision']);
    $this->assertSame(SyncStatus::ERROR, $this->state($item['uuid'])['status']);
  }

  /**
   * Works through the reconciliation queue as cron would.
   *
   * @return int
   *   Items processed before the worker stopped.
   */
  private function runQueue(): int {
    $queue = $this->container->get('queue')->get(Reconciler::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(Reconciler::QUEUE);
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
    return $processed;
  }

  /**
   * A run acts on a limited number of items; the queue does the rest.
   */
  public function testMaxItemsQueuesTheRest(): void {
    $this->config('content_relay_replica.settings')->set('reconcile.max_items', 2)->save();
    $items = [];
    foreach (['One', 'Two', 'Three'] as $title) {
      $item = $this->synced($title);
      $this->serveItem($this->retitle($item, "$title updated"), 2);
      $items[] = [$this->retitle($item, "$title updated"), 2];
    }
    $this->onOrigin($items);

    $run = $this->reconciler->run('manual');
    $this->assertSame('completed', $run['status']);
    $this->assertSame(2, $run['counts']['pulled']);
    $this->assertSame(1, $run['counts']['queued']);
    $this->expectExceptionMessageMatches('/still queued/');
    try {
      $this->reconciler->start('manual');
    }
    finally {
      $this->assertSame(1, $this->runQueue());
      foreach ($items as [$snapshot]) {
        $this->assertStringEndsWith('updated', $this->local($snapshot['uuid'])->label());
      }
      $this->assertSame(1, $this->reconciler->load((int) $run['id'])['counts']['queue_pulled']);
    }
  }

  /**
   * Turned away by the origin's read limit, a run waits and carries on.
   */
  public function testRateLimitedRunWaits(): void {
    $item = $this->synced('Before');
    $this->serveItem($this->retitle($item, 'After'), 2);
    $this->onOrigin([[$this->retitle($item, 'After'), 2]]);
    $this->updateFakeOrigin(fn (array &$config) => $config['rate_limited'] = 1);

    $run = $this->reconciler->run('manual');
    $this->assertSame('running', $run['status']);
    $this->assertSame(Reconciler::WAIT, $this->reconciler->step((int) $run['id']));

    // Later, it picks up where it stopped.
    $cursor = $run['cursor'];
    $cursor['wait_until'] = 0;
    $this->container->get('database')->update(Reconciler::TABLE)->fields(['cursor' => json_encode($cursor)])->condition('id', $run['id'])->execute();
    while ($this->reconciler->step((int) $run['id']) === Reconciler::MORE) {
      // Step through.
    }
    $this->assertSame('completed', $this->reconciler->load((int) $run['id'])['status']);
    $this->assertSame('After', $this->local($item['uuid'])->label());

    // A pull turned away is queued, and the queue waits too.
    $this->serveItem($this->retitle($item, 'Again'), 3);
    $this->onOrigin([[$this->retitle($item, 'Again'), 3]]);
    $this->updateFakeOrigin(fn (array &$config) => $config['rate_limited_items'] = 2);
    $run = $this->reconciler->run('manual');
    $this->assertSame(1, $run['counts']['queued']);
    $this->assertSame(0, $this->runQueue());
    $this->assertSame(1, $this->runQueue());
    $this->assertSame('Again', $this->local($item['uuid'])->label());
  }

}
