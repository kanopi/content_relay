<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\ManualPush;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pushing one item by hand.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ManualPushTest extends OriginTestBase {

  /**
   * The service under test.
   */
  private ManualPush $push;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->push = $this->container->get('content_relay_origin.manual_push');
    $this->pairAndAcknowledge();
    $account = User::create(['name' => 'pusher', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * A push sends the current revision now and reports the outcome.
   */
  public function testPushSendsNow(): void {
    $node = $this->node('article', 'Pushed');
    $this->deliver();
    $node->setTitle('Changed')->save();
    // The update recorded on save goes first; the push still goes out
    // itself, with the processing mode asked for.
    $result = $this->push->push($node, NULL, FALSE, ProcessingMode::Queued)[$this->replica->siteId];
    $this->assertSame(EventStatus::APPLIED, $result['status']);
    $this->assertSame(['create', 'update', 'update'], array_column($this->replica->events, 'operation'));
    $this->assertNull($this->replica->events[1]['process']);
    $this->assertSame('queued', $this->replica->events[2]['process']);

    $row = $this->container->get('content_relay_origin.outbox')->load($result['row']);
    $this->assertSame(Outbox::REASON_MANUAL, $row['reason']);
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $row['triggered_by']);
  }

  /**
   * An item the replica already has is reported in sync unless forced.
   */
  public function testInSyncUnlessForced(): void {
    $node = $this->node('article', 'Synced');
    $this->deliver();
    $this->assertCount(1, $this->replica->events);

    $results = $this->push->push($node);
    $this->assertSame(ManualPush::IN_SYNC, $results[$this->replica->siteId]['status']);
    $this->assertNull($results[$this->replica->siteId]['row']);
    $this->assertCount(1, $this->replica->events);

    $this->replica->eventResponses[] = [200, ['result' => 'unchanged']];
    $results = $this->push->push($node, [$this->replica->siteId], TRUE);
    $this->assertSame(EventStatus::APPLIED, $results[$this->replica->siteId]['status']);
    $this->assertCount(2, $this->replica->events);
    $this->assertSame($node->getRevisionId(), (string) $this->replica->events[1]['source_revision']);
  }

  /**
   * Outcomes other than applied are reported as they are.
   */
  public function testOutcomes(): void {
    $node = $this->node('article', 'Outcomes');
    $this->deliver();

    $this->replica->eventResponses[] = [202, ['result' => 'accepted']];
    $accepted = $this->push->push($node, NULL, TRUE)[$this->replica->siteId];
    $this->assertSame(EventStatus::ACCEPTED, $accepted['status']);
    $this->replica->eventResponses[] = [409, ['result' => 'stale']];
    $stale = $this->push->push($node, NULL, TRUE)[$this->replica->siteId];
    $this->assertSame(EventStatus::STALE, $stale['status']);
    $this->replica->eventResponses[] = [500, ['result' => 'error']];
    $failed = $this->push->push($node, NULL, TRUE)[$this->replica->siteId];
    $this->assertSame(EventStatus::FAILED, $failed['status']);
    $this->assertSame(ReportIndex::FAILED, $this->container->get('database')
      ->select(ReportIndex::TABLE, 'r')->fields('r', ['status'])->condition('uuid', $node->uuid())->execute()->fetchField());
  }

  /**
   * Not syncable and detached items are not pushed, with the reason.
   */
  public function testBlockedItemsAreNotPushed(): void {
    $node = $this->node('article', 'Blocked');
    $this->deliver();
    $control = $this->container->get('content_relay_origin.item_sync_control');

    $control->markNotSyncable($node);
    $this->deliver();
    $count = count($this->replica->events);
    $this->assertSame(ReportIndex::NOT_SYNCABLE, $this->push->push($node, NULL, TRUE)[$this->replica->siteId]['status']);
    $this->assertCount($count, $this->replica->events);

    $control->markSyncable($node);
    $this->deliver();
    $control->replicaDetached($this->replica->siteId, 'node', $node->uuid(), 'article', 'editor');
    $count = count($this->replica->events);
    $this->assertSame(ReportIndex::DETACHED_BY_REPLICA, $this->push->push($node, NULL, TRUE)[$this->replica->siteId]['status']);
    $this->assertCount($count, $this->replica->events);

    // A bundle the replica does not receive.
    $page = $this->node('page', 'Page');
    $this->assertSame([], $this->push->targets($page));
    $this->assertSame('not_in_contract', $this->push->push($page, [$this->replica->siteId])[$this->replica->siteId]['status']);
  }

}
