<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\Processing\ReceivedEvents;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Queue\RequeueException;
use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests immediate, queued and auto processing, and the status endpoint.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ProcessingTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Kernel tests use a lock backend that never blocks; these tests need
    // locks to be real.
    $this->container->set('lock', new DatabaseLockBackend($this->container->get('database')));
    $this->container->get('router.builder')->rebuild();
    // Status answers are signed by this replica.
    [, $material] = KeyPair::generate(KeyPurpose::Signing);
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY=' . $material);
    Key::create([
      'id' => 'replica_signing',
      'label' => 'Replica signing',
      'key_type' => 'content_relay_signing',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_REPLICA_TEST_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY');
    parent::tearDown();
  }

  /**
   * Puts contract version 2 in force, with a processing mode.
   */
  private function processing(string $mode): void {
    $terms = $this->contract(2, ['node' => ['article'], 'taxonomy_term' => ['tags']])->toArray();
    $terms['effective']['processing'] = ['default' => $mode, 'per_bundle' => []];
    $this->container->get('content_relay_replica.contracts')->save(SubscriptionContract::fromArray($terms));
  }

  /**
   * An event under contract version 2.
   */
  private function articleEvent(string $title, array $overrides = []): SyncEvent {
    return $this->event($this->originArticle($title), Operation::Create, 1, $overrides + ['contract_version' => 2]);
  }

  /**
   * Posts a signed message from the origin.
   */
  private function send(string $type, array $body, ?int $sequence = NULL): Response {
    $message = new Message((new PhpUuid())->generate(), $type, self::ORIGIN, $this->container->get('content_relay.site_identity')->getSiteId(), time(), EncryptionMode::None, $body, $sequence);
    $envelope = $this->container->get('content_relay.message_signer')->signMessage($message, $this->originKey)->toArray();
    $request = Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($envelope));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Posts an event.
   */
  private function deliver(SyncEvent $event): Response {
    return $this->send('event', $event->toArray(), $event->sequence);
  }

  /**
   * Asks for the status of events and returns the signed report's body.
   */
  private function askStatus(array $event_ids): array {
    $response = $this->send('status_request', ['event_ids' => $event_ids]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $status = json_decode((string) $response->getContent(), TRUE)['status'];
    $this->assertSame('status', $status['message']['type']);
    return $status['message']['body'];
  }

  /**
   * Runs the queued events worker.
   */
  private function runQueue(): void {
    $queue = $this->container->get('queue')->get(ReceivedEvents::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(ReceivedEvents::QUEUE);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * Immediate: applied before the answer.
   */
  public function testImmediate(): void {
    $this->processing('immediate');
    $event = $this->articleEvent('Now');
    $this->assertSame(200, $this->deliver($event)->getStatusCode());
    $this->assertNotNull($this->local($event->entityUuid));
  }

  /**
   * Queued: accepted, applied by the worker, final status from the endpoint.
   */
  public function testQueued(): void {
    $this->processing('queued');
    $event = $this->articleEvent('Later');
    $response = $this->deliver($event);
    $this->assertSame(202, $response->getStatusCode());
    $this->assertSame('queued', json_decode((string) $response->getContent(), TRUE)['code']);
    $this->assertNull($this->local($event->entityUuid));
    // Delivered again while waiting: accepted again, kept once.
    $this->assertSame(202, $this->deliver($event)->getStatusCode());
    $this->assertSame(1, $this->container->get('content_relay_replica.received_events')->backlog());
    $this->assertSame(['state' => 'pending'], $this->askStatus([$event->eventId])['events'][$event->eventId]);

    $this->runQueue();
    $this->assertSame('Later', $this->local($event->entityUuid)->label());
    $this->assertSame(0, $this->container->get('content_relay_replica.received_events')->backlog());
    $report = $this->askStatus([$event->eventId, '9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01']);
    $this->assertSame('final', $report['events'][$event->eventId]['state']);
    $this->assertSame(200, $report['events'][$event->eventId]['http_status']);
    $this->assertSame('applied', $report['events'][$event->eventId]['result']);
    $this->assertSame(['state' => 'unknown'], $report['events']['9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01']);
    $this->assertSame(2, $report['contract_version']);

    // Delivered again after: the recorded answer, not a second apply.
    $this->assertSame(200, $this->deliver($event)->getStatusCode());
  }

  /**
   * A per-event "immediate" from a manual push wins over queued.
   */
  public function testImmediateOverride(): void {
    $this->processing('queued');
    $event = $this->articleEvent('Pushed by hand', ['process' => 'immediate']);
    $this->assertSame(200, $this->deliver($event)->getStatusCode());
    $this->assertNotNull($this->local($event->entityUuid));
  }

  /**
   * Auto: queued for files, a busy item or a long queue; else immediate.
   */
  public function testAuto(): void {
    $this->processing('auto');
    $plain = $this->articleEvent('Plain');
    $this->assertSame(200, $this->deliver($plain)->getStatusCode());

    $with_files = $this->articleEvent('Has a file', ['files' => [['uuid' => 'x', 'sha256' => str_repeat('0', 64)]]]);
    $this->assertSame(202, $this->deliver($with_files)->getStatusCode());

    // The item being written by another process.
    $busy = $this->articleEvent('Busy');
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('content_relay_replica:apply:node:' . $busy->entityUuid, 60));
    $this->assertSame(202, $this->deliver($busy)->getStatusCode());
    $other->releaseAll();

    // A long queue.
    $this->config('content_relay_replica.settings')->set('processing.auto_backlog', 2)->save();
    $this->assertSame(202, $this->deliver($this->articleEvent('Behind others'))->getStatusCode());

    $this->runQueue();
    $this->assertSame('Busy', $this->local($busy->entityUuid)->label());
    $this->assertSame('final', $this->askStatus([$with_files->eventId])['events'][$with_files->eventId]['state']);
  }

  /**
   * A webhook and the worker never write one item at once.
   */
  public function testLockedItem(): void {
    $this->config('content_relay_replica.settings')->set('processing.lock_wait', 1)->save();
    $this->processing('queued');
    $queued = $this->articleEvent('Queued first');
    $this->assertSame(202, $this->deliver($queued)->getStatusCode());

    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('content_relay_replica:apply:node:' . $queued->entityUuid, 60));
    // Immediate while another process writes it: busy, for the origin to retry.
    $update = $this->event($this->retitle($queued->snapshot, 'Updated'), Operation::Update, 2, [
      'contract_version' => 2,
      'process' => 'immediate',
    ]);
    $response = $this->deliver($update);
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame('busy', json_decode((string) $response->getContent(), TRUE)['result']);
    // The worker puts it back rather than writing.
    $item = $this->container->get('queue')->get(ReceivedEvents::QUEUE)->claimItem();
    $this->assertIsObject($item);
    try {
      $this->container->get('plugin.manager.queue_worker')->createInstance(ReceivedEvents::QUEUE)->processItem($item->data);
      $this->fail('The worker wrote a locked item.');
    }
    catch (RequeueException) {
      $this->container->get('queue')->get(ReceivedEvents::QUEUE)->releaseItem($item);
    }
    $this->assertNull($this->local($queued->entityUuid));

    // Free again: the queued create, then the update, in that order.
    $other->releaseAll();
    $this->runQueue();
    $this->assertSame(200, $this->deliver($update)->getStatusCode());
    $this->assertCount(2, $this->revisions($this->local($queued->entityUuid)));
  }

  /**
   * Status requests are limited and checked.
   */
  public function testStatusRequestLimits(): void {
    $this->config('content_relay_replica.settings')->set('processing.status_batch_limit', 2)->save();
    $this->assertSame(400, $this->send('status_request', ['event_ids' => ['a', 'b', 'c']])->getStatusCode());
    $this->assertSame(400, $this->send('status_request', ['event_ids' => 'a'])->getStatusCode());
    $this->assertSame(['a' => ['state' => 'unknown']], $this->askStatus(['a'])['events']);
  }

  /**
   * The origin's validation asks what this site has; the answer is signed.
   */
  public function testHashRequest(): void {
    $snapshot = $this->originArticle('Asked about');
    $this->deliver($this->event($snapshot, Operation::Create, 1));
    $response = $this->send('hash_request', [
      'items' => [
      ['entity_type' => 'node', 'uuid' => $snapshot['uuid']],
      ['entity_type' => 'node', 'uuid' => '00000000-0000-4000-8000-000000000000'],
      ],
    ]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $hashes = json_decode((string) $response->getContent(), TRUE)['hashes'];
    $this->assertSame('hashes', $hashes['message']['type']);
    $items = $hashes['message']['body']['items'];
    $item = $items['node:' . $snapshot['uuid']];
    $this->assertTrue($item['synced']);
    $this->assertTrue($item['local_ok']);
    $this->assertSame(1, $item['revision']);
    $this->assertSame($this->container->get('content_relay.revision_hasher')->hash($snapshot)->translations, $item['translation_hashes']);
    $this->assertSame(['synced' => FALSE], $items['node:00000000-0000-4000-8000-000000000000']);

    // An edit here shows.
    $this->local($snapshot['uuid'])->setTitle('Edited here')->save();
    $response = $this->send('hash_request', ['items' => [['entity_type' => 'node', 'uuid' => $snapshot['uuid']]]]);
    $this->assertFalse(json_decode((string) $response->getContent(), TRUE)['hashes']['message']['body']['items']['node:' . $snapshot['uuid']]['local_ok']);
  }

  /**
   * With an allowlist, the origin is heard only from its addresses.
   */
  public function testOriginAllowlist(): void {
    $this->config('content_relay_replica.settings')->set('origin_allowed_ips', ['198.51.100.0/24'])->save();
    $response = $this->send('status_request', ['event_ids' => []]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('address_not_allowed', json_decode((string) $response->getContent(), TRUE)['result']);
    $this->config('content_relay_replica.settings')->set('origin_allowed_ips', ['127.0.0.1'])->save();
    $this->assertSame(200, $this->send('status_request', ['event_ids' => []])->getStatusCode());
  }

}
