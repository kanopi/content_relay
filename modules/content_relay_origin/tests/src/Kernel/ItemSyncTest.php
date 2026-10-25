<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\ItemSyncControl;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests not syncable flags and detaches reported by replicas.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ItemSyncTest extends OriginTestBase {

  /**
   * The service under test.
   */
  private ItemSyncControl $control;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->control = $this->container->get('content_relay_origin.item_sync_control');
    $this->pairAndAcknowledge();
  }

  /**
   * Operations of the events the replica received, in order.
   */
  private function operations(): array {
    return array_column($this->replica->events, 'operation');
  }

  /**
   * A node the replica already has.
   */
  private function synced(string $title = 'Synced'): NodeInterface {
    $node = $this->node('article', $title);
    $this->deliver();
    return $node;
  }

  /**
   * The item's report status for the fake replica.
   */
  private function reportStatus(NodeInterface $node): ?string {
    $status = $this->container->get('database')->select(ReportIndex::TABLE, 'r')
      ->fields('r', ['status'])
      ->condition('uuid', $node->uuid())
      ->condition('replica_id', $this->replica->siteId)
      ->execute()
      ->fetchField();
    return $status === FALSE ? NULL : $status;
  }

  /**
   * A not syncable item produces no events on create, update or delete.
   */
  public function testNotSyncableItemSendsNothing(): void {
    $node = $this->node('article', 'Secret');
    $this->control->markNotSyncable($node);
    // Recorded before marking, but skipped when its turn came.
    $this->deliver();
    $this->assertSame([], $this->replica->events);
    $row = $this->container->get('content_relay_origin.outbox')->latestFor($this->replica->siteId, 'node', $node->uuid());
    $this->assertSame(EventStatus::SKIPPED, $row['status']);
    $this->assertSame(ReportIndex::NOT_SYNCABLE, $row['result_code']);

    $node->setTitle('Still secret')->save();
    $node->delete();
    $this->deliver();
    $this->assertSame([], $this->replica->events);
    // The flag goes with the item.
    $this->assertSame([], $this->container->get('content_relay_origin.not_syncable')->get('node', $node->uuid()));
  }

  /**
   * Marking a synced item sends a detach, and nothing after it.
   */
  public function testMarkingSyncedItemDetaches(): void {
    $node = $this->synced();
    $this->assertSame([$this->replica->siteId], $this->control->markNotSyncable($node));
    $this->deliver();
    $this->assertSame(['create', 'detach'], $this->operations());
    $this->assertNull($this->replica->events[1]['snapshot']);
    $this->assertSame(ReportIndex::NOT_SYNCABLE, $this->reportStatus($node));

    $node->setTitle('Changed')->save();
    $this->deliver();
    $this->assertSame(['create', 'detach'], $this->operations());

    // Marking again changes nothing for the replica.
    $this->assertSame([], $this->control->markNotSyncable($node));
  }

  /**
   * The remove behavior sends a delete for an item the origin still has.
   */
  public function testRemoveBehaviorDeletesReplicaCopy(): void {
    $node = $this->synced();
    $this->control->markNotSyncable($node, NULL, NotSyncableFlags::REMOVE);
    $this->deliver();
    $this->assertSame(['create', 'delete'], $this->operations());
    $item = $this->container->get('content_relay_origin.replica_items')->get($this->replica->siteId, 'node', $node->uuid());
    $this->assertSame('deleted', $item['status']);
  }

  /**
   * The configured behavior is used when none is given.
   */
  public function testDefaultBehaviorIsConfigurable(): void {
    $this->assertSame(NotSyncableFlags::DETACH, $this->control->defaultBehavior());
    $this->config('content_relay_origin.settings')->set('not_syncable.behavior', NotSyncableFlags::REMOVE)->save();
    $node = $this->synced();
    $this->control->markNotSyncable($node);
    $this->deliver();
    $this->assertSame(['create', 'delete'], $this->operations());
  }

  /**
   * Unmarking sends an attach, then the whole item.
   */
  public function testUnmarkingSendsAttachAndPush(): void {
    $node = $this->synced();
    $this->control->markNotSyncable($node);
    $node->setTitle('Edited while not syncable')->save();
    $this->deliver();

    $this->assertSame([$this->replica->siteId], $this->control->markSyncable($node));
    $this->deliver();
    $this->assertSame(['create', 'detach', 'attach', 'update'], $this->operations());
    $this->assertSame('Edited while not syncable', $this->replica->events[3]['snapshot']['shared']['title'][0]['value']);
    $rows = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['reason'])->condition('operation', [
      'detach',
      'attach',
      'update',
    ], 'IN')->orderBy('sequence')->execute()->fetchCol();
    // The save while not syncable recorded nothing for the replica.
    $this->assertSame([Outbox::REASON_NOT_SYNCABLE, Outbox::REASON_ATTACH, Outbox::REASON_ATTACH], $rows);
    $this->assertSame(ReportIndex::IN_SYNC, $this->reportStatus($node));
  }

  /**
   * An item created while not syncable is created when unmarked.
   */
  public function testUnmarkingNeverSentItemCreatesIt(): void {
    $node = $this->node('article', 'Draft');
    $this->control->markNotSyncable($node);
    $this->deliver();
    $this->control->markSyncable($node);
    $this->deliver();
    $this->assertSame(['attach', 'update'], $this->operations());
  }

  /**
   * A flag for one replica leaves the others alone.
   */
  public function testReplicaScopedFlag(): void {
    $node = $this->synced();
    $this->assertSame([], $this->control->markNotSyncable($node, ['another-replica']));
    $node->setTitle('Still sent')->save();
    $this->deliver();
    $this->assertSame(['create', 'update'], $this->operations());

    $this->assertSame([$this->replica->siteId], $this->control->markNotSyncable($node, [$this->replica->siteId]));
    $node->setTitle('Not sent')->save();
    $this->deliver();
    $this->assertSame(['create', 'update', 'detach'], $this->operations());

    // Removing the other replica's flag does not resume this one.
    $this->assertSame([], $this->control->markSyncable($node, ['another-replica']));
    $this->assertSame([$this->replica->siteId], $this->control->markSyncable($node, [$this->replica->siteId]));
  }

  /**
   * A replica answering skipped_detached is recorded and no longer sent to.
   */
  public function testSkippedDetachedAnswer(): void {
    $node = $this->synced();
    $this->replica->eventResponses[] = [200, [
      'result' => 'skipped_detached',
      'details' => ['detached_by' => 'replica editor', 'detached_at' => 1700000000],
    ],
    ];
    $node->setTitle('First after detach')->save();
    $this->deliver();
    $row = $this->container->get('content_relay_origin.outbox')->latestFor($this->replica->siteId, 'node', $node->uuid());
    $this->assertSame(EventStatus::SKIPPED, $row['status']);
    $this->assertSame('skipped_detached', $row['result_code']);
    $this->assertSame(1, (int) $row['attempts'], 'Not retried.');

    $items = $this->container->get('content_relay_origin.replica_items');
    $this->assertTrue($items->isDetached($this->replica->siteId, 'node', $node->uuid()));
    $this->assertSame('replica editor', $items->get($this->replica->siteId, 'node', $node->uuid())['detached_by']);
    $this->assertSame(ReportIndex::DETACHED_BY_REPLICA, $this->reportStatus($node));

    $node->setTitle('Not sent')->save();
    $this->deliver();
    $this->assertCount(2, $this->replica->events);
    // Deletes still go: the replica decides whether to follow them.
    $node->delete();
    $this->deliver();
    $this->assertSame(['create', 'update', 'delete'], $this->operations());
  }

  /**
   * Detach and attach notices through the inbox.
   */
  public function testNoticesThroughInbox(): void {
    $node = $this->synced();
    $notice = [
      'entity_type' => 'node',
      'uuid' => $node->uuid(),
      'bundle' => 'article',
      'by' => 'editor',
      'at' => 1700000000,
    ];
    // A notice altered after signing is refused and changes nothing.
    $tampered = $this->replica->message('detach_notice', ['uuid' => $this->randomMachineName()] + $notice);
    $tampered['message']['body']['uuid'] = $node->uuid();
    $this->assertSame(403, $this->post('/content-relay/v1/messages', $tampered)->getStatusCode());
    $this->assertNotSame(ReportIndex::DETACHED_BY_REPLICA, $this->reportStatus($node));

    $response = $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(ReportIndex::DETACHED_BY_REPLICA, $this->reportStatus($node));

    $tampered = $this->replica->message('attach_notice', ['uuid' => $this->randomMachineName()] + $notice);
    $tampered['message']['body']['uuid'] = $node->uuid();
    $this->assertSame(403, $this->post('/content-relay/v1/messages', $tampered)->getStatusCode());
    $this->assertSame(ReportIndex::DETACHED_BY_REPLICA, $this->reportStatus($node));

    $node->setTitle('Changed')->save();
    $this->deliver();
    $this->assertSame(['create'], $this->operations());

    $response = $this->post('/content-relay/v1/messages', $this->replica->message('attach_notice', $notice));
    $this->assertTrue($this->json($response)['queued']);
    $this->deliver();
    $this->assertSame(['create', 'update'], $this->operations());
    $this->assertSame('Changed', $this->replica->events[1]['snapshot']['shared']['title'][0]['value']);

    // A notice missing what it is about is refused.
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', ['entity_type' => 'node']));
    $this->assertSame(400, $response->getStatusCode());
  }

  /**
   * Detached by both: each side's reversal alone does not resume updates.
   */
  public function testDetachedByBothSides(): void {
    $node = $this->synced();
    $notice = ['entity_type' => 'node', 'uuid' => $node->uuid(), 'bundle' => 'article'];
    $this->control->markNotSyncable($node);
    $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice));
    $this->deliver();
    $this->assertSame(['create', 'detach'], $this->operations());

    // The replica reattaches; the origin still has its flag.
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('attach_notice', $notice));
    $this->assertFalse($this->json($response)['queued']);
    $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice));

    // The origin unmarks; the replica has detached again. The attach goes,
    // the update is held back.
    $this->control->markSyncable($node);
    $this->deliver();
    $this->assertSame(['create', 'detach', 'attach'], $this->operations());

    // Only once both have reversed their detach does the item flow.
    $this->post('/content-relay/v1/messages', $this->replica->message('attach_notice', $notice));
    $this->deliver();
    $this->assertSame(['create', 'detach', 'attach', 'update'], $this->operations());
  }

  /**
   * A reference to an item not syncable for the replica is external.
   */
  public function testNotSyncableReferenceIsExternal(): void {
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_related',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_related'])->save();
    $hidden = $this->node('article', 'Hidden');
    $this->control->markNotSyncable($hidden);
    $node = Node::create(['type' => 'article', 'title' => 'Visible', 'uid' => 0, 'field_related' => [$hidden]]);
    $node->save();
    $this->deliver();
    $this->assertSame(['create'], $this->operations());
    $this->assertSame([], $this->replica->events[0]['dependencies']);
    $this->assertSame([['entity_type' => 'node', 'uuid' => $hidden->uuid()]], $this->replica->events[0]['external_references']);
  }

}
