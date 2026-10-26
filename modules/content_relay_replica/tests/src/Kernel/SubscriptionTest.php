<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\Processing\ReceivedEvents;
use Drupal\content_relay_replica\ProcessedEvents;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\StreamTracker;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SubscriptionManager;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests pairing and the subscription contract from the replica's side.
 *
 * The origin is the fake one in content_relay_test, reached through the
 * replica's real HTTP client.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class SubscriptionTest extends KernelTestBase {

  private const ORIGIN_URL = 'https://' . FakeOrigin::HOST;
  private const CODE = 'K7QM-3XRP-V9TD';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_relay_replica',
    'content_relay_test',
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
   * The fake origin's signing key.
   */
  private KeyPair $originKey;

  /**
   * This replica's signing key.
   */
  private KeyPair $replicaKey;

  /**
   * The service under test.
   */
  private SubscriptionManager $subscription;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('content_relay', ['content_relay_seen_message']);
    $this->installSchema('content_relay_replica', [
      SyncStateStore::TABLE,
      StreamTracker::TABLE,
      ProcessedEvents::TABLE,
      FailedEventStore::TABLE,
      FailedEventStore::DEPENDENCY_TABLE,
      ContractStore::TABLE,
      FileSync::TABLE,
      FileSync::WAIT_TABLE,
      ReceivedEvents::TABLE,
      Reconciler::TABLE,
    ]);
    $this->installConfig(['content_relay', 'content_relay_replica', 'node']);
    content_relay_install(FALSE);
    $this->container->get('router.builder')->rebuild();
    $this->config('content_relay_replica.settings')->set('webhook_url', 'https://replica.example.com/content-relay/v1/events')->save();

    [$this->replicaKey, $material] = KeyPair::generate(KeyPurpose::Signing);
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
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $this->originKey = FakeOrigin::install($this->container->get('state'), self::CODE, ['node' => ['article', 'page']]);
    $this->subscription = $this->container->get('content_relay_replica.subscription');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY');
    parent::tearDown();
  }

  /**
   * The fake origin's state.
   */
  private function origin(): array {
    return $this->container->get('state')->get(FakeOrigin::STATE);
  }

  /**
   * Changes the fake origin's state.
   */
  private function setOrigin(array $values): void {
    $this->container->get('state')->set(FakeOrigin::STATE, $values + $this->origin());
  }

  /**
   * Pairs with the fake origin.
   */
  private function pair(array $bundles = ['node' => ['article']]): array {
    return $this->subscription->pair(self::ORIGIN_URL, strtolower(str_replace('-', ' ', self::CODE)), ['bundles' => $bundles], 1);
  }

  /**
   * A contract message from the fake origin, signed.
   */
  private function originMessage(string $type, array $body): array {
    $message = new Message((new PhpUuid())->generate(), $type, FakeOrigin::SITE_ID, $this->container->get('content_relay.site_identity')->getSiteId(), time(), EncryptionMode::None, $body);
    return [
      'v' => 1,
      'sender' => FakeOrigin::SITE_ID,
      'signing_key_id' => $this->originKey->id(),
      'encryption' => 'none',
      'message' => $message->toArray(),
      'signature' => base64_encode($this->originKey->sign(MessageSigner::signedBytes($this->originKey->id(), $message->toArray()))),
    ];
  }

  /**
   * Pairing stores the origin and contract version 1 and acknowledges it.
   */
  public function testPairing(): void {
    $result = $this->pair(['node' => ['article', 'page', 'forum']]);
    $this->assertSame(1, $result['contract']->version());
    $this->assertTrue($result['acknowledged']);

    $origin = $this->container->get('content_relay_replica.origin')->get();
    $this->assertSame([FakeOrigin::SITE_ID, self::ORIGIN_URL], [$origin['site_id'], $origin['url']]);
    $this->assertSame(base64_encode($this->originKey->publicKey), $origin['signing_keys'][$this->originKey->id()]['public_key']);
    $this->assertSame(1, $this->container->get('content_relay_replica.contracts')->getCurrent()->version());
    $this->assertSame([1], $this->origin()['acks']);

    // The pairing request offered this replica's key and endpoint.
    $request = $this->origin()['requests'][0]['body'];
    $this->assertSame(base64_encode($this->replicaKey->publicKey), $request['signing_keys'][$this->replicaKey->id()]['public_key']);
    $this->assertSame('https://replica.example.com/content-relay/v1/events', $request['webhook_url']);
    $this->assertSame('node.forum', $result['contract']->notGranted()[0]['value']);

    // Receiving from it starts with the site-wide settings.
    $pairing = $this->container->get('content_relay_replica.receiving_settings')->pairing();
    $this->assertSame('default', $pairing->id());
    $this->assertSame('overwrite', $pairing->getSetting('conflicts.on_event'));
  }

  /**
   * Another origin needs a pairing name, and an origin pairs only once.
   */
  public function testFurtherPairings(): void {
    $this->assertSame('default', $this->pair()['pairing']);
    $attempts = [
      [NULL, 'Name the new pairing to add another.'],
      ['Second one', 'lowercase letters, digits and underscores'],
      ['default', 'The pairing "default" is already paired'],
      // The same origin again, under another name.
      ['second', 'already receives from that origin, as the pairing "default"'],
    ];
    foreach ($attempts as [$name, $message]) {
      try {
        $this->subscription->pair(self::ORIGIN_URL, self::CODE, ['bundles' => ['node' => ['article']]], 1, $name);
        $this->fail('Pairing was not refused: ' . $message);
      }
      catch (SubscriptionException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
    // Nothing is left of the refused pairing.
    $this->assertNull($this->container->get('content_relay_replica.origin')->get('second'));
    $this->assertSame(['default'], $this->container->get('content_relay_replica.active_pairing')->ids());
  }

  /**
   * An origin address that is not HTTPS is refused before anything is sent.
   */
  public function testHttpOriginIsRefused(): void {
    try {
      $this->subscription->pair('http://' . FakeOrigin::HOST, self::CODE, ['bundles' => []]);
      $this->fail('Paired over HTTP.');
    }
    catch (SubscriptionException $e) {
      $this->assertStringContainsString('HTTPS', $e->getMessage());
    }
    $this->assertSame([], $this->origin()['requests']);
  }

  /**
   * A refused code leaves the site unpaired.
   */
  public function testWrongCode(): void {
    $this->expectException(SubscriptionException::class);
    $this->expectExceptionMessage('The pairing code is not valid.');
    try {
      $this->subscription->pair(self::ORIGIN_URL, 'WRONG', ['bundles' => []]);
    }
    finally {
      $this->assertNull($this->container->get('content_relay_replica.origin')->get());
    }
  }

  /**
   * A change of languages produces version 2, stored and acknowledged.
   */
  public function testProposal(): void {
    $this->pair();
    $terms = $this->subscription->getReplicaTerms();
    $terms['languages'] = [
      'mode' => SubscriptionContract::LANGUAGES_SELECTED,
      'selected' => ['en', 'zh-hans'],
      'per_bundle' => [],
    ];
    $contract = $this->subscription->propose($terms, 1);
    $this->assertSame(2, $contract->version());
    $this->assertSame(['en', 'zh-hans'], $contract->languagesFor('node', 'article'));
    $this->assertSame([1, 2], $this->origin()['acks']);
    $this->assertSame(1, $this->container->get('content_relay_replica.contracts')->history()[2]['created_by']);
  }

  /**
   * A proposal that cannot reach the origin is kept and retried by cron.
   */
  public function testProposalRetried(): void {
    $this->pair();
    $this->setOrigin(['down' => TRUE]);
    $terms = ['bundles' => ['node' => ['article', 'page']]] + $this->subscription->getReplicaTerms();
    $this->assertNull($this->subscription->propose($terms, 1));
    $this->assertTrue($this->subscription->hasPendingProposal());
    $this->assertSame(1, $this->container->get('content_relay_replica.contracts')->getCurrent()->version(), 'The old contract stays in force.');

    $this->setOrigin(['down' => FALSE]);
    content_relay_replica_cron();
    $this->assertFalse($this->subscription->hasPendingProposal());
    $this->assertTrue($this->container->get('content_relay_replica.contracts')->getCurrent()->allowsBundle('node', 'page'));
  }

  /**
   * A contract pushed by the origin is stored and acknowledged in the reply.
   */
  public function testPushedContract(): void {
    $this->pair(['node' => ['article', 'page']]);
    $current = $this->container->get('content_relay_replica.contracts')->getCurrent()->toArray();
    $pushed = [
      'version' => 2,
      'issued_by' => 'origin',
      'effective' => ['bundles' => ['node' => ['article']]] + $current['effective'],
    ] + $current;

    $request = Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->originMessage('contract', $pushed)));
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $ack = json_decode((string) $response->getContent(), TRUE)['ack'];
    $this->assertSame(['contract_ack', ['version' => 2], FakeOrigin::SITE_ID], [
      $ack['message']['type'],
      $ack['message']['body'],
      $ack['message']['recipient'],
    ]);
    $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($ack['signature']), MessageSigner::signedBytes($ack['signing_key_id'], $ack['message']), $this->replicaKey->publicKey));
    $this->assertSame(2, $this->container->get('content_relay_replica.contracts')->getCurrent()->version());

    // A tampered contract is refused and changes nothing.
    $tampered = $this->originMessage('contract', ['version' => 3] + $pushed);
    $tampered['message']['body']['effective']['bundles'] = ['node' => ['article', 'page']];
    $request = Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($tampered));
    $this->assertSame(403, $this->container->get('http_kernel')->handle($request)->getStatusCode());
    $this->assertSame(2, $this->container->get('content_relay_replica.contracts')->getCurrent()->version());
  }

  /**
   * Withdrawn bundles detach their items; granting them again reattaches.
   */
  public function testWithdrawnBundleDetachesItems(): void {
    $this->pair(['node' => ['article', 'page']]);
    $state = $this->container->get('content_relay_replica.sync_state');
    $row = [
      'source_site' => FakeOrigin::SITE_ID,
      'source_revision' => 1,
      'hash_algorithm' => 1,
      'source_hash' => str_repeat('a', 64),
      'source_translation_hashes' => [],
      'status' => SyncStatus::SYNCED,
    ];
    $state->save('node', 'page-1', ['bundle' => 'page'] + $row);
    $state->save('node', 'page-2', ['bundle' => 'page', 'status' => SyncStatus::DETACHED_BY_REPLICA] + $row);
    $state->save('node', 'article-1', ['bundle' => 'article'] + $row);

    $this->setOrigin(['allowed' => ['node' => ['article']]]);
    $this->subscription->propose(['bundles' => ['node' => ['article', 'page']]] + $this->subscription->getReplicaTerms());
    $this->assertSame(SyncStatus::DETACHED_BY_ORIGIN, $state->get('node', 'page-1')['status']);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $state->get('node', 'page-2')['status'], 'The replica\'s own detach is left alone.');
    $this->assertSame(SyncStatus::SYNCED, $state->get('node', 'article-1')['status']);

    $this->setOrigin(['allowed' => ['node' => ['article', 'page']]]);
    $this->subscription->propose(['bundles' => ['node' => ['article', 'page']]] + $this->subscription->getReplicaTerms());
    $this->assertSame(SyncStatus::SYNCED, $state->get('node', 'page-1')['status']);
  }

}
