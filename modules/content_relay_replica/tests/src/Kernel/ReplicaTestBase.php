<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\Processing\ReceivedEvents;
use Drupal\content_relay_replica\ProcessedEvents;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\StreamTracker;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;

/**
 * Sets up a replica with an article type and a paired origin.
 *
 * Events are made the way the origin makes them: by normalizing a real entity.
 * The entity is then deleted, so the replica sees an item it does not have.
 */
abstract class ReplicaTestBase extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;

  protected const ORIGIN = 'origin-site';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_relay_replica',
    'content_relay_test',
    'entity_test',
    'key',
    'node',
    'system',
    'taxonomy',
    'text',
    'field',
    'filter',
    'user',
    'views',
  ];

  /**
   * The origin's signing key.
   */
  protected KeyPair $originKey;

  /**
   * Sequence of the next event made.
   */
  protected int $sequence = 1;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('entity_test_mul');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('content_relay', ['content_relay_seen_message', 'content_relay_file_checksum']);
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
    $this->installConfig(['content_relay', 'content_relay_replica', 'node', 'filter']);
    content_relay_install(FALSE);

    NodeType::create(['type' => 'article', 'name' => 'Article', 'new_revision' => TRUE])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_tags', 'Tags', 'taxonomy_term', 'default', [], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    [$this->originKey] = KeyPair::generate(KeyPurpose::Signing);
    $this->container->get('content_relay_replica.origin')->set([
      'site_id' => self::ORIGIN,
      'label' => 'Origin',
      'url' => 'https://origin.example.com',
      'signing_keys' => [
        $this->originKey->id() => ['public_key' => base64_encode($this->originKey->publicKey), 'status' => 'primary'],
      ],
    ]);
    $this->container->get('content_relay_replica.contracts')->save($this->contract(1, [
      'node' => ['article'],
      'taxonomy_term' => ['tags'],
    ]));
  }

  /**
   * A contract from the origin to this replica.
   */
  protected function contract(int $version, array $bundles): SubscriptionContract {
    return new SubscriptionContract([
      'version' => $version,
      'origin' => self::ORIGIN,
      'replica' => $this->container->get('content_relay.site_identity')->getSiteId(),
      'effective_from_sequence' => 1,
      'issued' => 1700000000,
      'issued_by' => 'replica',
      'origin_terms' => SubscriptionContract::defaultOriginTerms(),
      'replica_terms' => ['bundles' => $bundles] + SubscriptionContract::defaultReplicaTerms(),
      'joint' => [],
      'effective' => ['bundles' => $bundles],
      'not_granted' => [],
    ]);
  }

  /**
   * The snapshot of an article that exists only on the origin.
   */
  protected function originArticle(string $title = 'Hello', array $values = []): array {
    $node = Node::create($values + ['type' => 'article', 'title' => $title, 'uid' => 0]);
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node)), TRUE);
    $node->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    return $snapshot;
  }

  /**
   * Builds an event for a snapshot, hashed as the origin would.
   */
  protected function event(?array $snapshot, Operation $operation, int $revision, array $overrides = []): SyncEvent {
    $data = [
      'v' => SyncEvent::SCHEMA_VERSION,
      'event_id' => (new PhpUuid())->generate(),
      'sequence' => $this->sequence,
      'previous_sequence' => $this->sequence > 1 ? $this->sequence - 1 : NULL,
      'operation' => $operation->value,
      'entity_type' => $snapshot['entity_type'] ?? 'node',
      'bundle' => $snapshot['bundle'] ?? 'article',
      'entity_uuid' => $snapshot['uuid'] ?? $overrides['entity_uuid'],
      'source_revision' => $revision,
      'revisionable' => TRUE,
      'source_changed' => 1700000000 + $revision,
      'default_langcode' => $snapshot['default_langcode'] ?? 'und',
      'langcodes' => $snapshot ? array_keys($snapshot['translations']) : [],
      'hash' => $snapshot ? $this->container->get('content_relay.revision_hasher')->hash($snapshot)->toArray() : NULL,
      'contract_version' => 1,
      'created' => 1700000000,
      'dependencies' => [],
      'external_references' => [],
      'files' => [],
      'process' => NULL,
      'snapshot' => $snapshot,
    ];
    $this->sequence++;
    return SyncEvent::fromArray($overrides + $data);
  }

  /**
   * Applies an event as the origin's.
   */
  protected function apply(SyncEvent $event): ApplyResult {
    return $this->container->get('content_relay_replica.applier')->apply($event, self::ORIGIN);
  }

  /**
   * Loads the local copy of an item, fresh from storage.
   */
  protected function local(string $uuid): ?NodeInterface {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $node = $this->container->get('entity.repository')->loadEntityByUuid('node', $uuid);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * The revision IDs of a node.
   *
   * @return int[]
   *   Revision IDs.
   */
  protected function revisions(NodeInterface $node): array {
    return array_keys($this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('nid', $node->id())
      ->execute());
  }

  /**
   * Sets the title in a snapshot.
   */
  protected function retitle(array $snapshot, string $title): array {
    $snapshot['shared']['title'][0]['value'] = $title;
    return $snapshot;
  }

  /**
   * Points this replica at the fake origin, for pulls and other requests.
   *
   * The replica gets a signing key of its own, for its requests, and trusts
   * the fake origin's signing key. Events built by event() are still signed
   * off as from self::ORIGIN, which apply() passes as the source.
   */
  protected function useFakeOrigin(): void {
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
    $origin_key = FakeOrigin::install($this->container->get('state'), 'UNUSED', []);
    $this->updateFakeOrigin(fn (array &$config) => $config['replica'] = $this->container->get('content_relay.site_identity')->getSiteId());
    $this->container->get('content_relay_replica.origin')->set([
      'site_id' => FakeOrigin::SITE_ID,
      'label' => 'Fake origin',
      'url' => 'https://' . FakeOrigin::HOST,
      'signing_keys' => [
        $origin_key->id() => ['public_key' => base64_encode($origin_key->publicKey), 'status' => 'primary'],
      ],
    ]);
  }

  /**
   * Changes what the fake origin does.
   *
   * @param callable $change
   *   Changes its configuration, given by reference.
   */
  protected function updateFakeOrigin(callable $change): void {
    $state = $this->container->get('state');
    $config = $state->get(FakeOrigin::STATE);
    $change($config);
    $state->set(FakeOrigin::STATE, $config);
  }

  /**
   * Has the fake origin serve an item, as its item endpoint would.
   */
  protected function serveItem(array $snapshot, int $revision): void {
    $event = $this->event($snapshot, Operation::Update, $revision)->toArray();
    $this->updateFakeOrigin(function (array &$config) use ($snapshot, $event): void {
      $config['items'][$snapshot['entity_type'] . ':' . $snapshot['uuid']] = $event;
    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY');
    parent::tearDown();
  }

}
