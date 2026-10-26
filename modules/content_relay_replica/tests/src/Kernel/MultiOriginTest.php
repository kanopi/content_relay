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
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\content_relay_test\FakeOrigin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests a replica receiving from two origins.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MultiOriginTest extends ReplicaTestBase {

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
    $receiving->ensurePairing('Origin');

    // A second origin, which sends tags and articles, with its own settings.
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
      $receiving->ensurePairing('Asia')->setSetting('conflicts.on_event', 'reject')->save();
    });
  }

  /**
   * Signs an event as one of the origins.
   */
  private function envelope(SyncEvent $event, string $sender, KeyPair $key): array {
    $message = new Message((new PhpUuid())->generate(), 'event', $sender, $this->container->get('content_relay.site_identity')->getSiteId(), time(), EncryptionMode::None, $event->toArray(), $event->sequence);
    return $this->container->get('content_relay.message_signer')->signMessage($message, $key)->toArray();
  }

  /**
   * Posts to the endpoint.
   */
  private function post(array $body): Response {
    $request = Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Applies an event as one origin.
   */
  private function applyFrom(string $sender, SyncEvent $event): ApplyResult {
    return $this->container->get('content_relay_replica.applier')->apply($event, $sender);
  }

  /**
   * Each origin's events arrive through its own pairing.
   */
  public function testEachOriginItsOwnPairing(): void {
    $active = $this->container->get('content_relay_replica.active_pairing');
    $this->assertSame(['asia', ContentRelayOriginPairing::DEFAULT], $active->ids());
    $this->assertSame('asia', $active->forSite(self::ASIA));

    // Over the wire, signed by the second origin.
    $from_asia = $this->originArticle('From Asia');
    $response = $this->post($this->envelope($this->event($from_asia, Operation::Create, 1), self::ASIA, $this->asiaKey));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(self::ASIA, $this->container->get('content_relay_replica.sync_state')->get('node', $from_asia['uuid'])['source_site']);

    // Signed with the other origin's key, it is refused.
    $response = $this->post($this->envelope($this->event($this->originArticle('Forged'), Operation::Create, 1), self::ASIA, $this->originKey));
    $this->assertSame(403, $response->getStatusCode());
    $response = $this->post($this->envelope($this->event($this->originArticle('Stranger'), Operation::Create, 1), 'stranger', $this->asiaKey));
    $this->assertSame(403, $response->getStatusCode());

    // Each pairing's contract: the second origin does not send tags.
    $tag = $this->event(['entity_type' => 'taxonomy_term', 'bundle' => 'tags'] + $this->originArticle('Tag'), Operation::Create, 1, [
      'entity_type' => 'taxonomy_term',
      'bundle' => 'tags',
    ]);
    $this->assertSame(ApplyResult::CONTRACT_MISMATCH, $this->applyFrom(self::ASIA, $tag)->result);

    // Each pairing's settings: edits here are overwritten from the first
    // origin and kept from the second.
    $from_origin = $this->originArticle('From the first origin');
    $this->applyFrom(self::ORIGIN, $this->event($from_origin, Operation::Create, 1));
    foreach ([$from_origin, $from_asia] as $snapshot) {
      $this->local($snapshot['uuid'])->setTitle('Edited here')->save();
    }
    $this->assertSame(ApplyResult::APPLIED, $this->applyFrom(self::ORIGIN, $this->event($this->retitle($from_origin, 'Updated'), Operation::Update, 2))->result);
    $this->assertSame('Updated', $this->local($from_origin['uuid'])->label());
    $this->assertSame(ApplyResult::CONFLICT, $this->applyFrom(self::ASIA, $this->event($this->retitle($from_asia, 'Updated'), Operation::Update, 2))->result);
    $this->assertSame('Edited here', $this->local($from_asia['uuid'])->label());
  }

  /**
   * The origin that created an item owns it.
   */
  public function testFirstOriginOwnsItem(): void {
    $snapshot = $this->originArticle('Owned');
    $this->applyFrom(self::ORIGIN, $this->event($snapshot, Operation::Create, 1));

    $result = $this->applyFrom(self::ASIA, $this->event($this->retitle($snapshot, 'Taken over'), Operation::Update, 5));
    $this->assertSame([422, 'uuid_collision'], [$result->httpStatus, $result->code]);
    $this->assertStringContainsString('owns it', (string) $result->message);
    $result = $this->applyFrom(self::ASIA, $this->event(NULL, Operation::Delete, 6, ['entity_uuid' => $snapshot['uuid']]));
    $this->assertSame('uuid_collision', $result->code);
    $this->assertSame('Owned', $this->local($snapshot['uuid'])->label());
    $this->assertSame(self::ORIGIN, $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['source_site']);

    // The owner still can.
    $this->assertSame(ApplyResult::APPLIED, $this->applyFrom(self::ORIGIN, $this->event($this->retitle($snapshot, 'By its owner'), Operation::Update, 2))->result);
  }

  /**
   * Reconciling one origin leaves the other's items alone.
   */
  public function testReconcileLeavesOtherOriginsAlone(): void {
    // The first origin is the fake one, which answers hash listings.
    $active = $this->container->get('content_relay_replica.active_pairing');
    $active->run(ContentRelayOriginPairing::DEFAULT, fn () => $this->useFakeOrigin());
    $mine = $this->originArticle('The first origin has it');
    $this->applyFrom(FakeOrigin::SITE_ID, $this->event($mine, Operation::Create, 1));
    $theirs = $this->originArticle('The second origin has it');
    $this->applyFrom(self::ASIA, $this->event($theirs, Operation::Create, 1));
    $hasher = $this->container->get('content_relay.revision_hasher');
    $this->updateFakeOrigin(function (array &$config) use ($mine, $hasher): void {
      $config['hashes'] = [
        'node:article' => [
          [
            'uuid' => $mine['uuid'],
            'revision' => 1,
            'changed' => 1700000001,
            'hash' => $hasher->hash($mine)->toArray(),
            'not_syncable' => FALSE,
          ],
        ],
        'taxonomy_term:tags' => [],
      ];
    });

    $run = $active->run(ContentRelayOriginPairing::DEFAULT, fn () => $this->container->get('content_relay_replica.reconciler')->run('manual'));
    $this->assertSame('completed', $run['status'], json_encode($run));
    $this->assertArrayNotHasKey('deleted', $run['counts']);
    $this->assertNotNull($this->local($theirs['uuid']), 'The second origin\'s item is not the first origin\'s to delete.');
    $this->assertNotNull($this->local($mine['uuid']));
    $this->assertSame(FakeOrigin::SITE_ID, $this->container->get('content_relay_replica.origin')->getSiteId(ContentRelayOriginPairing::DEFAULT));
  }

  /**
   * Without a pairing chosen, work that needs one says so.
   */
  public function testPairingMustBeChosen(): void {
    $this->expectException(\LogicException::class);
    $this->container->get('content_relay_replica.contracts')->getCurrent();
  }

}
