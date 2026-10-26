<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests ending a pairing with an origin.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class UnpairTest extends ReplicaTestBase {

  /**
   * The second origin's site ID.
   */
  private const ASIA = 'asia-site';

  /**
   * The second origin's signing key.
   */
  private KeyPair $asiaKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
    $receiving = $this->container->get('content_relay_replica.receiving_settings');
    $active = $this->container->get('content_relay_replica.active_pairing');
    // The first origin is the fake one, which records the notices it gets.
    $this->useFakeOrigin();
    $receiving->ensurePairing('Fake origin');

    [$this->asiaKey] = KeyPair::generate(KeyPurpose::Signing);
    $active->run('asia', function () use ($receiving): void {
      $this->container->get('content_relay_replica.origin')->set([
        'site_id' => self::ASIA,
        'label' => 'Asia',
        'url' => 'https://asia.example.com',
        'signing_keys' => [
          $this->asiaKey->id() => ['public_key' => base64_encode($this->asiaKey->publicKey), 'status' => 'primary'],
        ],
      ]);
      $this->container->get('content_relay_replica.contracts')->save($this->contract(1, ['node' => ['article']]));
      $receiving->ensurePairing('Asia');
    });
  }

  /**
   * Applies an event as one origin.
   */
  private function applyFrom(string $sender, SyncEvent $event): void {
    $this->assertNull($this->container->get('content_relay_replica.applier')->apply($event, $sender)->code);
  }

  /**
   * Received items, by origin.
   *
   * @return array<string, string[]>
   *   UUIDs by source site.
   */
  private function received(): array {
    $rows = $this->container->get('database')->select('content_relay_sync_state', 's')->fields('s', [
      'source_site',
      'uuid',
    ])->execute()->fetchAll();
    $received = [];
    foreach ($rows as $row) {
      $received[$row->source_site][] = $row->uuid;
    }
    return $received;
  }

  /**
   * Keeping leaves the content, as this site's own; the other origin's stays.
   */
  public function testKeep(): void {
    $kept = $this->originArticle('From Asia');
    $this->applyFrom(self::ASIA, $this->event($kept, Operation::Create, 1));
    $mine = $this->originArticle('From the first origin');
    $this->applyFrom(FakeOrigin::SITE_ID, $this->event($mine, Operation::Create, 1));

    $unpairer = $this->container->get('content_relay_replica.unpairer');
    $this->assertSame(['received' => 1, 'detached' => 0], $unpairer->count('asia'));
    $result = $unpairer->unpair('asia', FALSE);
    $this->assertSame(['deleted' => 0, 'kept' => 1], array_intersect_key($result, ['deleted' => 0, 'kept' => 0]));

    // The content stays, released: nothing of the pairing is left.
    $this->assertNotNull($this->local($kept['uuid']));
    $this->assertSame([FakeOrigin::SITE_ID => [$mine['uuid']]], $this->received());
    $active = $this->container->get('content_relay_replica.active_pairing');
    $this->assertSame([ContentRelayOriginPairing::DEFAULT], $active->ids());
    $this->assertNull($this->container->get('content_relay_replica.origin')->get('asia'));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('content_relay_origin_pairing')->load('asia'));
    $contracts = $this->container->get('database')->select(ContractStore::TABLE, 'c')->fields('c', ['pairing'])->execute()->fetchCol();
    $this->assertSame([ContentRelayOriginPairing::DEFAULT], array_values(array_unique($contracts)));

    // The origin's events are refused from now on.
    $event = $this->event($this->retitle($kept, 'Again'), Operation::Update, 2);
    $message = new Message((new PhpUuid())->generate(), 'event', self::ASIA, $this->container->get('content_relay.site_identity')->getSiteId(), time(), EncryptionMode::None, $event->toArray(), $event->sequence);
    $body = json_encode($this->container->get('content_relay.message_signer')->signMessage($message, $this->asiaKey)->toArray());
    $response = $this->container->get('http_kernel')->handle(Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('From Asia', $this->local($kept['uuid'])->label());

    // It is local content now: another origin does not change it unless it
    // is adopted.
    $result = $this->container->get('content_relay_replica.applier')->apply($this->event($this->retitle($kept, 'Taken over'), Operation::Update, 5), FakeOrigin::SITE_ID);
    $this->assertSame('uuid_collision', $result->code);
    $this->assertSame('From Asia', $this->local($kept['uuid'])->label());
  }

  /**
   * Deleting removes what came from the origin, except what is detached.
   */
  public function testDelete(): void {
    $gone = $this->originArticle('Deleted');
    $detached = $this->originArticle('Detached');
    foreach ([$gone, $detached] as $snapshot) {
      $this->applyFrom(FakeOrigin::SITE_ID, $this->event($snapshot, Operation::Create, 1));
    }
    $this->container->get('content_relay_replica.sync_state')->setStatus('node', $detached['uuid'], SyncStatus::DETACHED_BY_REPLICA);
    $theirs = $this->originArticle('From Asia');
    $this->applyFrom(self::ASIA, $this->event($theirs, Operation::Create, 1));

    $unpairer = $this->container->get('content_relay_replica.unpairer');
    $this->assertSame(['received' => 1, 'detached' => 1], $unpairer->count(ContentRelayOriginPairing::DEFAULT));
    $result = $unpairer->unpair(ContentRelayOriginPairing::DEFAULT, TRUE);
    $this->assertSame(['notified' => TRUE, 'deleted' => 1, 'kept' => 1], $result);

    $this->assertNull($this->local($gone['uuid']));
    $this->assertNotNull($this->local($detached['uuid']));
    $this->assertNotNull($this->local($theirs['uuid']));
    $this->assertSame([self::ASIA => [$theirs['uuid']]], $this->received());
    $this->assertSame(['asia'], $this->container->get('content_relay_replica.active_pairing')->ids());
    $notices = $this->container->get('state')->get(FakeOrigin::STATE)['notices'] ?? [];
    $this->assertSame('unpair_notice', end($notices)['type']);
  }

  /**
   * Work queued for a pairing since ended is dropped.
   */
  public function testQueuedWorkOfEndedPairing(): void {
    $this->container->get('content_relay_replica.unpairer')->unpair('asia', FALSE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('content_relay_replica_reconcile');
    $worker->processItem([
      'entity_type' => 'node',
      'uuid' => (new PhpUuid())->generate(),
      'current' => 'missing',
      'pairing' => 'asia',
    ]);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('content_relay_replica_languages');
    $worker->processItem(['pairing' => 'asia']);
    $this->addToAssertionCount(1);
  }

}
