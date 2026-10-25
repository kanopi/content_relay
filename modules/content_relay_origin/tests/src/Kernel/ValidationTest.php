<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\HashStore;
use Drupal\content_relay_origin\ItemValidator;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validation from the origin, the read allowlist and status counts.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ValidationTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->pairAndAcknowledge();
  }

  /**
   * Has the fake replica say what it has of an item.
   */
  private function replicaHas(NodeInterface $node, array $answer): void {
    $latest = $this->container->get('content_relay_origin.hash_store')->latest('node', $node->uuid());
    $this->replica->hashAnswers['node:' . $node->uuid()] = $answer + [
      'synced' => TRUE,
      'revision' => $latest['revision'],
      'hash_algorithm' => $latest['hashes']->algorithmVersion,
      'translation_hashes' => $latest['hashes']->translations,
      'status' => 'synced',
      'local_ok' => TRUE,
      'present' => TRUE,
      'detached' => FALSE,
      'detached_translations' => [],
      'local_translations' => [],
    ];
  }

  /**
   * The result for the fake replica.
   */
  private function validate(NodeInterface $node): array {
    return $this->container->get('content_relay_origin.item_validator')->validate($node)['replicas'][$this->replica->siteId];
  }

  /**
   * Each answer a replica can give.
   */
  public function testResults(): void {
    $node = $this->node('article', 'Checked');
    $this->deliver();

    $this->replicaHas($node, []);
    $this->assertSame(ItemValidator::IN_SYNC, $this->validate($node)['result']);

    $this->replicaHas($node, ['translation_hashes' => ['en' => str_repeat('0', 64)]]);
    $result = $this->validate($node);
    $this->assertSame(ItemValidator::BEHIND, $result['result']);
    $this->assertSame(['en'], $result['differing']);
    // What it said is recorded, so the report agrees.
    $this->assertSame(ReportIndex::OUT_OF_SYNC, $this->container->get('database')->select(ReportIndex::TABLE, 'r')->fields('r', ['status'])->condition('uuid', $node->uuid())->execute()->fetchField());

    $this->replicaHas($node, ['local_ok' => FALSE]);
    $this->assertSame(ItemValidator::CHANGED_ON_REPLICA, $this->validate($node)['result']);
    $this->replicaHas($node, ['detached' => TRUE]);
    $this->assertSame(ItemValidator::DETACHED, $this->validate($node)['result']);
    $this->replica->hashAnswers = [];
    $this->assertSame(ItemValidator::MISSING, $this->validate($node)['result']);
    $this->replica->down = TRUE;
    $this->assertSame(ItemValidator::UNREACHABLE, $this->validate($node)['result']);
  }

  /**
   * A stored hash that no longer matches the content is recomputed.
   */
  public function testStaleHashHere(): void {
    $node = $this->node('article', 'Changed behind our back');
    $this->container->get('database')->update(HashStore::TABLE)->fields(['entity_hash' => str_repeat('f', 64)])->execute();
    $this->replicaHas($node, []);
    $result = $this->container->get('content_relay_origin.item_validator')->validate($node);
    $this->assertTrue($result['origin_stale']);
    $this->assertNotSame(str_repeat('f', 64), $this->container->get('content_relay_origin.hash_store')->latest('node', $node->uuid())['hashes']->entity);
  }

  /**
   * Requests from addresses not on a replica's list are refused.
   */
  public function testAllowlist(): void {
    $registry = $this->container->get('content_relay_origin.replica_registry');
    $registry->setAllowedIps($this->replica->siteId, ['203.0.113.0/24']);
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('address_not_allowed', $this->json($response)['result']);

    $response = $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]), '203.0.113.9');
    $this->assertSame(200, $response->getStatusCode());

    $registry->setAllowedIps($this->replica->siteId, []);
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]))->getStatusCode());
  }

  /**
   * Events per state, for the status command.
   */
  public function testCounts(): void {
    $this->node('article', 'One');
    $this->node('article', 'Two');
    $this->assertSame(['queued' => 2], $this->container->get('content_relay_origin.outbox')->countsByStatus($this->replica->siteId));
    $this->deliver();
    $this->assertSame(['applied' => 2], $this->container->get('content_relay_origin.outbox')->countsByStatus($this->replica->siteId));
  }

}
