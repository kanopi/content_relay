<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\Outbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the subscription contract from the origin's side.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ContractTest extends OriginTestBase {

  /**
   * The contract manager.
   */
  private ContractManager $contracts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->contracts = $this->container->get('content_relay_origin.contract_manager');
    $this->pairAndAcknowledge();
  }

  /**
   * Proposes replica terms through the inbox, as the replica would.
   */
  private function propose(array $terms): array {
    $terms += SubscriptionContract::defaultReplicaTerms();
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('subscription_update', ['replica_terms' => $terms]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return $this->json($response)['contract']['message']['body'];
  }

  /**
   * Acknowledges a version through the inbox.
   */
  private function acknowledge(int $version): void {
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => $version]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * A replica's change produces a new version, in force once acknowledged.
   */
  public function testReplicaChangeIsVersioned(): void {
    $terms = $this->propose(['bundles' => ['node' => ['article', 'page']]]);
    $this->assertSame(2, $terms['version']);
    $this->assertSame(['node' => ['article', 'page']], $terms['effective']['bundles']);
    $this->assertSame(1, $this->contracts->getActive($this->replica->siteId)->version(), 'Version 1 stays in force until 2 is acknowledged.');

    // Before the acknowledgement, a page is not sent; the article is, under 1.
    $this->node('article');
    $this->node('page');
    $this->replica->dropAcks = TRUE;
    $this->deliver();
    $this->assertSame([['article', 1]], array_map(fn ($e) => [$e['bundle'], $e['contract_version']], $this->replica->events));

    $this->acknowledge(2);
    $active = $this->contracts->getActive($this->replica->siteId);
    $this->assertSame(2, $active->version());
    $this->assertSame(1, $active->effectiveFromSequence(), 'The signed terms keep the sequence proposed.');
    $this->assertSame(2, $this->contracts->history($this->replica->siteId)[2]['effective_from'], 'In force from the first event not yet sent.');

    // Pages saved from now on are sent under version 2.
    $this->node('page', 'After');
    $this->deliver();
    $last = end($this->replica->events);
    $this->assertSame(['page', 2, 'After'], [
      $last['bundle'],
      $last['contract_version'],
      $last['snapshot']['shared']['title'][0]['value'],
    ]);
  }

  /**
   * A bundle the origin does not allow is listed as not granted, and skipped.
   */
  public function testRequestedButNotGranted(): void {
    $terms = $this->propose(['bundles' => ['node' => ['article', 'forum']]]);
    $this->assertSame(['node' => ['article']], $terms['effective']['bundles']);
    $this->assertSame('node.forum', $terms['not_granted'][0]['value']);
    $this->assertSame('The origin does not publish it.', $terms['not_granted'][0]['reason']);
  }

  /**
   * The origin withdraws a bundle: pushed, acknowledged, events skipped.
   */
  public function testOriginWithdrawsBundle(): void {
    $this->propose(['bundles' => ['node' => ['article', 'page']]]);
    $this->acknowledge(2);
    $this->node('page', 'Queued before the withdrawal');

    $contract = $this->contracts->propose($this->replica->siteId, NULL, ['allowed_bundles' => ['node' => ['article']]], 'origin', 1);
    $this->assertTrue($this->contracts->push($this->replica->siteId, TRUE));
    $this->assertSame([3], array_slice($this->replica->contracts, -1));
    $this->assertSame(3, $this->contracts->getActive($this->replica->siteId)->version());
    $this->assertSame([['term' => 'bundle', 'value' => 'node.page', 'reason' => 'Not allowed for this replica.']], $contract->notGranted());

    $this->deliver();
    $this->assertSame([], $this->replica->events);
    $statuses = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['status'])->execute()->fetchCol();
    $this->assertSame([EventStatus::SKIPPED], $statuses);

    // New pages are not even recorded for this replica.
    $this->node('page');
    $this->assertCount(1, $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o')->execute()->fetchAll());
  }

  /**
   * An acknowledgement that never comes leaves the old version in force.
   */
  public function testLostAcknowledgementIsRetried(): void {
    $this->replica->dropAcks = TRUE;
    $this->contracts->propose($this->replica->siteId, NULL, ['allowed_bundles' => NULL, 'max_events_per_minute' => 10], 'origin', 1);
    $this->assertFalse($this->contracts->push($this->replica->siteId, TRUE));
    $this->assertSame(1, $this->contracts->getActive($this->replica->siteId)->version());

    // Cron sends it again once the interval has passed.
    $this->container->get('database')->update(ContractManager::TABLE)->fields(['last_push' => 0])->execute();
    $this->replica->dropAcks = FALSE;
    content_relay_origin_cron();
    $this->assertSame(2, $this->contracts->getActive($this->replica->siteId)->version());
  }

  /**
   * An event under a contract the replica lacks is resent after the contract.
   */
  public function testContractMismatchResendsContract(): void {
    $this->node('article');
    $this->replica->eventResponses[] = [
      409,
      ['result' => 'contract_mismatch', 'details' => ['current_version' => NULL]],
    ];
    $pushes = count($this->replica->contracts);
    $this->deliver();
    $this->assertCount($pushes + 1, $this->replica->contracts, 'The contract was sent again.');
    $row = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o')->execute()->fetchAssoc();
    $this->assertSame(EventStatus::FAILED, $row['status']);
    $this->assertLessThanOrEqual(time(), (int) $row['next_attempt'], 'Retried at once.');

    $this->deliver();
    $this->assertCount(2, $this->replica->events);
    $this->assertSame(EventStatus::APPLIED, $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['status'])->execute()->fetchField());
  }

  /**
   * A paused replica gets nothing; on resume everything arrives in order.
   */
  public function testPauseAndResume(): void {
    $this->propose([
      'bundles' => ['node' => ['article']],
      'delivery' => ['paused' => TRUE] + SubscriptionContract::defaultReplicaTerms()['delivery'],
    ]);
    $this->acknowledge(2);
    $this->node('article', 'One');
    $this->node('article', 'Two');
    $this->deliver();
    $this->assertSame([], $this->replica->events);

    $this->propose(['bundles' => ['node' => ['article']]]);
    $this->acknowledge(3);
    $this->deliver();
    $this->assertSame(['One', 'Two'], array_map(fn ($e) => $e['snapshot']['shared']['title'][0]['value'], $this->replica->events));
  }

  /**
   * Rate limits and delivery windows are respected.
   */
  public function testRateLimitAndWindows(): void {
    $this->propose([
      'bundles' => ['node' => ['article']],
      'delivery' => ['max_events_per_minute' => 2] + SubscriptionContract::defaultReplicaTerms()['delivery'],
    ]);
    $this->acknowledge(2);
    foreach (['a', 'b', 'c'] as $title) {
      $this->node('article', $title);
    }
    $this->deliver();
    $this->assertCount(2, $this->replica->events);
    $this->deliver();
    $this->assertCount(2, $this->replica->events, 'Nothing more within the same minute.');

    // A window that excludes now holds delivery.
    $hour = (int) gmdate('G');
    $closed = ['start' => sprintf('%02d:00', ($hour + 2) % 24), 'end' => sprintf('%02d:00', ($hour + 3) % 24)];
    $this->propose([
      'bundles' => ['node' => ['article']],
      'delivery' => ['windows' => [$closed]] + SubscriptionContract::defaultReplicaTerms()['delivery'],
    ]);
    $this->acknowledge(3);
    $this->deliver();
    $this->assertCount(2, $this->replica->events);
  }

  /**
   * Messages that do not verify are refused.
   */
  public function testTamperedMessagesAreRefused(): void {
    $proposal = $this->replica->message('subscription_update', ['replica_terms' => ['bundles' => ['node' => ['page']]]]);
    $proposal['message']['body']['replica_terms']['bundles']['node'][] = 'article';
    $this->assertSame(403, $this->post('/content-relay/v1/messages', $proposal)->getStatusCode());

    $ack = $this->replica->message('contract_ack', ['version' => 2]);
    $ack['message']['body']['version'] = 1;
    $this->assertSame(403, $this->post('/content-relay/v1/messages', $ack)->getStatusCode());
    $this->assertCount(1, $this->contracts->history($this->replica->siteId));
  }

  /**
   * History keeps every version with who changed it and what changed.
   */
  public function testHistory(): void {
    $this->propose(['bundles' => ['node' => ['article', 'page']]]);
    $this->acknowledge(2);
    $this->contracts->propose($this->replica->siteId, NULL, ['allowed_bundles' => ['node' => ['article']]], 'origin', 1);

    $history = $this->contracts->history($this->replica->siteId);
    $this->assertSame([3, 2, 1], array_keys($history));
    $this->assertSame([ContractManager::PENDING, ContractManager::ACTIVE, ContractManager::SUPERSEDED], array_column($history, 'status'));
    $this->assertSame(['origin', 'replica', 'replica'], array_column($history, 'initiated_by'));
    $paths = array_column($history[2]['contract']->diff($history[1]['contract']), 'path');
    $this->assertContains('effective.bundles.node', $paths);
    $this->assertContains('replica_terms.bundles.node', $paths);
    $this->assertNotContains('version', $paths);
  }

  /**
   * The plain-language summary says what the stored terms say.
   */
  public function testSummary(): void {
    $this->propose([
      'bundles' => ['node' => ['article', 'page', 'forum']],
      'delivery' => ['paused' => TRUE, 'max_events_per_minute' => 30] + SubscriptionContract::defaultReplicaTerms()['delivery'],
    ]);
    $summary = $this->container->get('content_relay.contract_summarizer')->summarize($this->contracts->getLatest($this->replica->siteId));
    $this->assertSame('You receive Article and Page in all languages, immediately, unencrypted. Delivery is paused. At most 30 events a minute. Requested but not granted: node.forum (The origin does not publish it.).', $summary);
  }

}
