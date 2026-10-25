<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\ReplicaItems;
use Drupal\content_relay_origin\Report\ReportIndex;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests resolving accepted events through the replica status endpoint.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class StatusPollTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pairAndAcknowledge();
  }

  /**
   * Saves an article the replica accepts for later, and delivers it.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: array}
   *   The article and its event log row.
   */
  private function accepted(string $title): array {
    $this->replica->eventResponses[] = [202, ['result' => 'accepted', 'code' => 'queued']];
    $node = $this->node('article', $title);
    $this->deliver();
    $row = $this->container->get('content_relay_origin.outbox')->latestFor($this->replica->siteId, 'node', $node->uuid());
    $this->assertSame(EventStatus::ACCEPTED, $row['status']);
    return [$node, $row];
  }

  /**
   * Final answers settle accepted events; pending ones wait.
   */
  public function testSettled(): void {
    [$applied_node, $applied] = $this->accepted('Applied later');
    [, $failed] = $this->accepted('Failed later');
    [, $pending] = $this->accepted('Still waiting');
    $this->replica->statuses = [
      $applied['event_id'] => ['state' => 'final', 'http_status' => 200, 'result' => 'applied'],
      $failed['event_id'] => [
        'state' => 'final',
        'http_status' => 422,
        'result' => 'apply_failed',
        'code' => 'validation_failed',
      ],
      $pending['event_id'] => ['state' => 'pending'],
    ];

    $counts = $this->container->get('content_relay_origin.status_poller')->pollAll()[$this->replica->siteId];
    $this->assertSame([EventStatus::APPLIED => 1, EventStatus::FAILED_ON_REPLICA => 1, 'pending' => 1], $counts);
    $this->assertSame([[$applied['event_id'], $failed['event_id'], $pending['event_id']]], $this->replica->statusRequests);

    $outbox = $this->container->get('content_relay_origin.outbox');
    $this->assertSame(EventStatus::APPLIED, $outbox->load((int) $applied['id'])['status']);
    $this->assertSame(EventStatus::FAILED_ON_REPLICA, $outbox->load((int) $failed['id'])['status']);
    $this->assertSame(EventStatus::ACCEPTED, $outbox->load((int) $pending['id'])['status']);

    // What the replica now has is known, so the report says in sync.
    $item = $this->container->get('content_relay_origin.replica_items')->get($this->replica->siteId, 'node', $applied_node->uuid());
    $this->assertSame(ReplicaItems::APPLIED, $item['status']);
    $this->assertSame($this->container->get('content_relay_origin.hash_store')->latest('node', $applied_node->uuid())['hashes']->entity, $item['entity_hash']);
    $status = $this->container->get('database')->select(ReportIndex::TABLE, 'r')->fields('r', ['status'])->condition('uuid', $applied_node->uuid())->execute()->fetchField();
    $this->assertSame(ReportIndex::IN_SYNC, $status);

    // Only the one still pending is asked about next time.
    $this->container->get('content_relay_origin.status_poller')->pollAll();
    $this->assertSame([$pending['event_id']], end($this->replica->statusRequests));
  }

  /**
   * An event the replica does not know is sent again.
   */
  public function testUnknownIsResent(): void {
    [$node, $row] = $this->accepted('Lost on the replica');
    $this->assertSame(['resent' => 1], $this->container->get('content_relay_origin.status_poller')->poll($this->replica->siteId));
    $this->deliver();
    $last = end($this->replica->events);
    $this->assertSame($row['event_id'], $last['event_id']);
    $this->assertSame($node->uuid(), $last['entity_uuid']);
    $this->assertSame(EventStatus::APPLIED, $this->container->get('content_relay_origin.outbox')->load((int) $row['id'])['status']);
  }

  /**
   * A replica on another contract version is sent the one in force.
   */
  public function testContractVersionMismatch(): void {
    $this->accepted('Any');
    $contracts = count($this->replica->contracts);
    $this->replica->reportedContractVersion = 7;
    $this->container->get('content_relay_origin.status_poller')->poll($this->replica->siteId);
    $this->assertCount($contracts + 1, $this->replica->contracts);
  }

  /**
   * Nothing is asked when nothing is accepted, or the replica is down.
   */
  public function testNothingToAsk(): void {
    $this->assertSame([], $this->container->get('content_relay_origin.status_poller')->pollAll());
    $this->accepted('Waiting');
    $this->replica->down = TRUE;
    $this->assertSame([], $this->container->get('content_relay_origin.status_poller')->poll($this->replica->siteId));
  }

}
