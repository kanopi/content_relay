<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\DeliveryScheduler;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\HashStore;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\PairingCodes;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\ReplicaItems;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests recording changes, delivering them and handling every response.
 *
 * The HTTP layer is a mock that answers each request with the next queued
 * response, and records what was sent.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class DeliveryTest extends KernelTestBase {

  private const REPLICA = 'replica-one';
  private const ENDPOINT = 'https://replica.example.com/content-relay/v1/events';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_relay_origin',
    'key',
    'node',
    'system',
    'text',
    'field',
    'filter',
    'user',
    'views',
  ];

  /**
   * Responses to give, in order.
   */
  private MockHandler $responses;

  /**
   * Requests sent, each with 'request' and 'response'.
   *
   * @var array<int, array{request: \Psr\Http\Message\RequestInterface}>
   */
  private array $sent = [];

  /**
   * The origin's signing key.
   */
  private KeyPair $key;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // First: saving any entity builds services that hold the HTTP client.
    $this->responses = new MockHandler();
    $stack = HandlerStack::create($this->responses);
    $stack->push(Middleware::history($this->sent));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('content_relay', ['content_relay_seen_message']);
    $this->installSchema('content_relay_origin', [
      ReplicaRegistry::TABLE,
      Outbox::TABLE,
      HashStore::TABLE,
      ContractManager::TABLE,
      PairingCodes::TABLE,
      ReplicaItems::TABLE,
      NotSyncableFlags::TABLE,
      ReportIndex::TABLE,
    ]);
    $this->installConfig(['content_relay', 'content_relay_origin', 'node', 'filter']);
    content_relay_install(FALSE);

    [$this->key, $material] = KeyPair::generate(KeyPurpose::Signing);
    putenv('CONTENT_RELAY_ORIGIN_TEST_KEY=' . $material);
    Key::create([
      'id' => 'origin_signing',
      'label' => 'Origin signing',
      'key_type' => 'content_relay_signing',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_ORIGIN_TEST_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->config('content_relay_origin.settings')
      ->set('bundles', ['node' => ['article']])
      ->set('delivery.max_attempts', 3)
      ->save();

    $this->container->get('content_relay_origin.replica_registry')->save(new Replica(self::REPLICA, 'Replica one', self::ENDPOINT, Replica::ACTIVE, []));
    $contracts = $this->container->get('content_relay_origin.contract_manager');
    $contracts->propose(self::REPLICA, ['bundles' => ['node' => ['article']]], NULL, 'replica');
    $contracts->acknowledge(self::REPLICA, 1);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_ORIGIN_TEST_KEY');
    parent::tearDown();
  }

  /**
   * Saves an article.
   */
  private function article(string $title = 'Hello'): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'uid' => 0]);
    $node->save();
    return $node;
  }

  /**
   * Event log rows, oldest first.
   */
  private function rows(): array {
    $result = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o')->orderBy('sequence')->execute()->fetchAll();
    $rows = [];
    foreach ($result as $row) {
      $rows[$row->id] = (array) $row;
    }
    return $rows;
  }

  /**
   * The event body of a sent request.
   */
  private function sentEvent(int $index): array {
    $envelope = json_decode((string) $this->sent[$index]['request']->getBody(), TRUE);
    // The signature is checked properly in the replica tests; here it is
    // enough that it verifies against the origin's key.
    $bytes = MessageSigner::signedBytes($envelope['signing_key_id'], $envelope['message']);
    $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($envelope['signature']), $bytes, $this->key->publicKey));
    $this->assertSame(self::REPLICA, $envelope['message']['recipient']);
    return $envelope['message']['body'];
  }

  /**
   * Delivers to the replica.
   */
  private function deliver(): bool {
    return $this->container->get('content_relay_origin.delivery')->deliver(self::REPLICA);
  }

  /**
   * Makes every pending row due now.
   */
  private function makeDue(): void {
    $this->container->get('database')->update(Outbox::TABLE)->fields(['next_attempt' => 0])->execute();
  }

  /**
   * Saving records a row and a hash, sends nothing, and skips other bundles.
   */
  public function testSaveRecordsLocallyOnly(): void {
    $node = $this->article();
    Node::create(['type' => 'page', 'title' => 'Not synced', 'uid' => 0])->save();

    $this->assertCount(0, $this->sent, 'No HTTP request during the save.');
    $rows = array_values($this->rows());
    $this->assertCount(1, $rows);
    $this->assertSame(['create', 'node', 'article', $node->uuid(), EventStatus::QUEUED, '1'], [
      $rows[0]['operation'], $rows[0]['entity_type'], $rows[0]['bundle'], $rows[0]['entity_uuid'], $rows[0]['status'], (string) $rows[0]['sequence'],
    ]);
    $latest = $this->container->get('content_relay_origin.hash_store')->latest('node', $node->uuid());
    $this->assertSame((int) $node->getRevisionId(), $latest['revision']);
    $this->assertSame(1, $this->container->get('queue')->get(DeliveryScheduler::QUEUE)->numberOfItems());
  }

  /**
   * Events go out in order, carrying the stream position and a snapshot.
   */
  public function testDeliveryInOrder(): void {
    $nodes = [$this->article('One'), $this->article('Two'), $this->article('Three')];
    $nodes[0]->delete();
    $this->responses->append(new Response(200, [], '{"result":"applied"}'), new Response(200), new Response(200));

    $this->assertFalse($this->deliver());
    // The first create was superseded by its delete, never sent.
    $this->assertCount(3, $this->sent);
    $first = $this->sentEvent(0);
    $this->assertSame(
      [2, NULL, 'create', 'Two'],
      [
        $first['sequence'],
        $first['previous_sequence'],
        $first['operation'],
        $first['snapshot']['shared']['title'][0]['value'],
      ],
    );
    $this->assertSame(
      $first['hash'],
      $this->container->get('content_relay.revision_hasher')->hash($first['snapshot'])->toArray(),
    );
    $this->assertSame([3, 2], [$this->sentEvent(1)['sequence'], $this->sentEvent(1)['previous_sequence']]);
    $delete = $this->sentEvent(2);
    $this->assertSame(
      ['delete', 4, 3, NULL],
      [$delete['operation'], $delete['sequence'], $delete['previous_sequence'], $delete['snapshot']],
    );

    $statuses = array_column($this->rows(), 'status', 'sequence');
    $this->assertSame(
      [1 => EventStatus::SUPERSEDED, 2 => EventStatus::APPLIED, 3 => EventStatus::APPLIED, 4 => EventStatus::APPLIED],
      $statuses,
    );
    $this->assertNotNull($this->container->get('content_relay_origin.replica_registry')->get(self::REPLICA)->lastSuccess);
  }

  /**
   * Several updates waiting for one entity go out as one event.
   */
  public function testQueuedUpdatesAreCoalesced(): void {
    $node = $this->article('v1');
    $node->setTitle('v2')->save();
    $node->setTitle('v3')->save();
    $this->responses->append(new Response(200));
    $this->deliver();
    $this->assertCount(1, $this->sent);
    $this->assertSame('v3', $this->sentEvent(0)['snapshot']['shared']['title'][0]['value']);
    $this->assertSame(
      [EventStatus::APPLIED, EventStatus::SUPERSEDED, EventStatus::SUPERSEDED],
      array_column($this->rows(), 'status'),
    );
  }

  /**
   * A server error is retried with backoff and holds back later events.
   */
  public function testServerErrorIsRetried(): void {
    $this->article('One');
    $this->article('Two');
    $this->responses->append(new Response(500, [], 'down'));
    $this->deliver();
    $this->assertCount(1, $this->sent);
    [$first, $second] = array_values($this->rows());
    $this->assertSame(EventStatus::FAILED, $first['status']);
    $this->assertGreaterThan(time(), (int) $first['next_attempt']);
    $this->assertSame('500', (string) $first['last_http_status']);
    $this->assertSame(EventStatus::QUEUED, $second['status'], 'Nothing overtakes a retrying event.');

    // Not due yet: nothing is sent.
    $this->deliver();
    $this->assertCount(1, $this->sent);

    $this->makeDue();
    $this->responses->append(new Response(200), new Response(200));
    $this->deliver();
    $this->assertCount(3, $this->sent);
    $this->assertSame(
      [EventStatus::APPLIED, EventStatus::APPLIED],
      array_column($this->rows(), 'status'),
    );
    $this->assertSame('2', (string) array_values($this->rows())[0]['attempts']);
    $this->assertSame($this->sentEvent(0)['event_id'], $this->sentEvent(1)['event_id'], 'A retry is the same event.');
  }

  /**
   * Timeouts are retried, then dead-lettered.
   */
  public function testTimeoutsDeadLetter(): void {
    $this->article();
    for ($i = 0; $i < 3; $i++) {
      $this->responses->append(new ConnectException('Timed out', new Request('POST', self::ENDPOINT)));
      $this->makeDue();
      $this->deliver();
    }
    $row = array_values($this->rows())[0];
    $this->assertSame(EventStatus::DEAD_LETTER, $row['status']);
    $this->assertSame('3', (string) $row['attempts']);
    $this->assertSame('transport_error', $row['result_code']);
  }

  /**
   * Permanent refusals, conflicts and replica-side failures are not retried.
   */
  public function testFinalResponses(): void {
    $this->article('Bad');
    $this->article('Stale');
    $this->article('Failed');
    $this->article('Accepted');
    $this->responses->append(
      new Response(400, [], '{"result":"bad_signature"}'),
      new Response(409, [], '{"result":"stale"}'),
      new Response(422, [], '{"result":"apply_failed","code":"validation_failed","message":"Title: too long"}'),
      new Response(202, [], '{"result":"accepted"}'),
    );
    $this->deliver();
    $this->assertCount(4, $this->sent);
    $rows = array_values($this->rows());
    $this->assertSame(
      [EventStatus::DEAD_LETTER, EventStatus::STALE, EventStatus::FAILED_ON_REPLICA, EventStatus::ACCEPTED],
      array_column($rows, 'status'),
    );
    $this->assertSame(['1', '1', '1', '1'], array_map('strval', array_column($rows, 'attempts')));
    $this->assertStringContainsString('Title: too long', $rows[2]['last_response']);

    // Nothing more is sent, however often delivery runs.
    $this->makeDue();
    $this->deliver();
    $this->assertCount(4, $this->sent);
  }

  /**
   * Replay sends the entity as it is now, not as it was.
   */
  public function testReplaySendsCurrentState(): void {
    $node = $this->article('Old');
    $this->responses->append(new Response(400));
    $this->deliver();
    $failed = (int) array_key_first($this->rows());

    $node->setTitle('New')->save();
    // The update queued behind it is delivered normally.
    $this->responses->append(new Response(200));
    $this->deliver();

    $new = $this->container->get('content_relay_origin.replay')->replay($failed);
    $this->responses->append(new Response(200));
    $this->deliver();

    $this->assertSame(
      EventStatus::REPLAYED,
      $this->container->get('content_relay_origin.outbox')->load($failed)['status'],
    );
    $replayed = $this->sentEvent(2);
    $this->assertSame(
      ['update', 'New'],
      [$replayed['operation'], $replayed['snapshot']['shared']['title'][0]['value']],
    );
    $this->assertSame(
      (string) $failed,
      (string) $this->container->get('content_relay_origin.outbox')->load($new)['replay_of'],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->container->get('content_relay_origin.replay')->replay($new);
  }

  /**
   * A replayed delete of an entity that has come back is an update.
   */
  public function testReplayOfDeleteForRecreatedEntity(): void {
    $node = $this->article('Gone');
    $uuid = $node->uuid();
    $node->delete();
    $this->responses->append(new Response(400));
    $this->deliver();
    $rows = array_values($this->rows());
    $this->assertSame([EventStatus::SUPERSEDED, EventStatus::DEAD_LETTER], array_column($rows, 'status'));

    // The entity comes back with the same UUID, e.g. restored from a backup.
    Node::create(['type' => 'article', 'title' => 'Back', 'uid' => 0, 'uuid' => $uuid])->save();
    $this->container->get('content_relay_origin.replay')->replay((int) $rows[1]['id']);
    $this->responses->append(new Response(200));
    $this->deliver();
    // The replay was decided afresh as an update, and then folded into the
    // recreation's own create, which already carries the current state. No
    // second delete goes out.
    $this->assertCount(2, $this->sent);
    $this->assertSame('delete', $this->sentEvent(0)['operation']);
    $this->assertSame('create', $this->sentEvent(1)['operation']);
    $this->assertSame('Back', $this->sentEvent(1)['snapshot']['shared']['title'][0]['value']);
    $replay = array_values($this->rows())[3];
    $this->assertSame(['update', EventStatus::SUPERSEDED], [$replay['operation'], $replay['status']]);
  }

  /**
   * Pruning removes only acknowledged events past the retention period.
   */
  public function testPruning(): void {
    foreach (['a', 'b', 'c'] as $title) {
      $this->article($title);
    }
    $this->responses->append(new Response(200), new Response(400), new Response(500));
    $this->deliver();
    $database = $this->container->get('database');
    $database->update(Outbox::TABLE)->fields(['changed' => time() - 61 * 86400])->execute();
    $this->article('recent');
    $database->update(Outbox::TABLE)->fields(['status' => EventStatus::APPLIED])->condition('sequence', 4)->execute();

    $this->assertSame(1, $this->container->get('content_relay_origin.outbox')->prune());
    $this->assertSame(
      [EventStatus::DEAD_LETTER, EventStatus::FAILED, EventStatus::APPLIED],
      array_column($this->rows(), 'status'),
    );
  }

  /**
   * The queue worker delivers, and cron queues retries that came due.
   */
  public function testQueueAndCron(): void {
    $this->article();
    $queue = $this->container->get('queue')->get(DeliveryScheduler::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(DeliveryScheduler::QUEUE);
    $this->responses->append(new Response(503));
    $item = $queue->claimItem();
    $this->assertIsObject($item);
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertSame(0, $queue->numberOfItems());

    $this->makeDue();
    content_relay_origin_cron();
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * Suspended replicas keep queueing but receive nothing.
   */
  public function testSuspendedReplicaIsHeld(): void {
    $registry = $this->container->get('content_relay_origin.replica_registry');
    $registry->save(new Replica(self::REPLICA, 'Replica one', self::ENDPOINT, Replica::SUSPENDED, []));
    $this->article();
    $this->deliver();
    $this->assertCount(0, $this->sent);
    $this->assertCount(1, $this->rows());
  }

}
