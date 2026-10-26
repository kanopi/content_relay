<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the pages for a replica with several origins.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class OriginsUiTest extends BrowserTestBase {

  use DrushTestTrait;
  use FileKeyTrait;

  private const CODE = 'K7QM-3XRP-V9TD';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_relay_replica', 'content_relay_test', 'node', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * UUID of the article received from the fake origin.
   */
  private string $uuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->createFileKey('replica_signing');
    FakeOrigin::install($this->container->get('state'), self::CODE, ['node' => ['article']]);
    $this->container->get('content_relay_replica.subscription')->pair('https://' . FakeOrigin::HOST, self::CODE, ['bundles' => ['node' => ['article']]]);

    // A second origin, as pairing would leave it.
    $this->container->get('content_relay_replica.active_pairing')->run('asia', function (): void {
      $this->container->get('content_relay_replica.origin')->set([
        'site_id' => 'asia-site',
        'label' => 'Asia',
        'url' => 'https://asia.example.com',
        'signing_keys' => [],
      ]);
      $this->container->get('content_relay_replica.receiving_settings')->ensurePairing('Asia');
    });

    $node = Node::create(['type' => 'article', 'title' => 'From the origin', 'uid' => 0]);
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node)), TRUE);
    $node->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    $event = SyncEvent::fromArray([
      'v' => SyncEvent::SCHEMA_VERSION,
      'event_id' => (new PhpUuid())->generate(),
      'sequence' => 1,
      'previous_sequence' => NULL,
      'operation' => 'create',
      'entity_type' => 'node',
      'bundle' => 'article',
      'entity_uuid' => $snapshot['uuid'],
      'source_revision' => 1,
      'revisionable' => TRUE,
      'source_changed' => 1700000001,
      'default_langcode' => $snapshot['default_langcode'],
      'langcodes' => array_keys($snapshot['translations']),
      'hash' => $this->container->get('content_relay.revision_hasher')->hash($snapshot)->toArray(),
      'contract_version' => 1,
      'created' => 1700000000,
      'dependencies' => [],
      'external_references' => [],
      'files' => [],
      'process' => NULL,
      'snapshot' => $snapshot,
    ]);
    $result = $this->container->get('content_relay_replica.applier')->apply($event, FakeOrigin::SITE_ID);
    $this->assertSame('applied', $result->result, (string) $result->message);
    $this->uuid = $event->entityUuid;

    $this->drupalLogin($this->drupalCreateUser([
      'administer content relay replica',
      'change content relay subscription',
      'reconcile content relay items',
      'view content relay replica report',
      'administer content relay mappings',
    ]));
  }

  /**
   * Each origin is listed, with pages of its own.
   */
  public function testOrigins(): void {
    $this->drupalGet('admin/config/services/content-relay/replica');
    $this->assertSession()->pageTextContains('Fake origin');
    $this->assertSession()->pageTextContains('Asia');
    $this->assertSession()->pageTextContains('https://asia.example.com');
    $this->assertSession()->linkExists('Pair with another origin');

    // Settings per origin.
    $this->drupalGet('admin/config/services/content-relay/replica/origins/asia/settings');
    $this->assertSession()->pageTextContains('How content from Asia is applied here.');
    $this->submitForm(['on_event' => 'reject'], 'Save configuration');
    $this->assertSame('reject', $this->pairing('asia')->getSetting('conflicts.on_event'));
    $this->assertNotSame('reject', $this->pairing('default')->getSetting('conflicts.on_event'));

    // The contract page is the origin's.
    $this->drupalGet('admin/config/services/content-relay/replica/origins/default');
    $this->assertSession()->pageTextContains('Fake origin (https://' . FakeOrigin::HOST . ')');
    $this->drupalGet('admin/config/services/content-relay/replica/origins/nowhere');
    $this->assertSession()->statusCodeEquals(404);

    // Reconciliation from the report asks which origin.
    $this->drupalGet('admin/reports/content-relay/received/reconciliation');
    $this->assertSession()->pageTextContains('Reconcile with which origin?');
    $this->clickLink('Asia');
    $this->assertSession()->addressEquals('admin/config/services/content-relay/replica/origins/asia/reconciliation');
    $this->assertSession()->buttonExists('Run reconciliation now');

    // The report shows and filters by origin.
    $this->drupalGet('admin/reports/content-relay/received', ['query' => ['origin' => FakeOrigin::SITE_ID]]);
    $this->assertSession()->pageTextContains('From the origin');
    $this->drupalGet('admin/reports/content-relay/received', ['query' => ['origin' => 'asia-site']]);
    $this->assertSession()->pageTextNotContains('From the origin');

    // A mapping can be for one origin.
    $this->drupalGet('admin/config/services/content-relay/replica/mappings/add');
    $this->assertSession()->optionExists('pairing', 'asia');
    $this->assertSession()->optionExists('pairing', '');
  }

  /**
   * Unpairing asks what happens to the content, every time.
   */
  public function testUnpair(): void {
    $this->drupalGet('admin/config/services/content-relay/replica/origins/asia/unpair');
    $this->assertSession()->pageTextContains('Unpair from Asia?');
    $this->submitForm([], 'Unpair');
    $this->assertSession()->pageTextContains('field is required');
    $this->submitForm(['what' => 'keep'], 'Unpair');
    $this->assertSession()->pageTextContains('Asia could not be told');
    $this->assertSession()->pageTextContains('Unpaired from Asia. 0 item(s) are kept as content of this site.');
    $this->assertSession()->addressEquals('admin/config/services/content-relay/replica');
    $this->assertSession()->pageTextNotContains('https://asia.example.com');

    // Deleting runs as a batch, and the origin is told.
    $this->drupalGet('admin/config/services/content-relay/replica/origins/default/unpair');
    $this->assertSession()->pageTextContains('Delete it (1 items)');
    $this->submitForm(['what' => 'delete'], 'Unpair');
    $this->assertSession()->pageTextContains('Unpaired from Fake origin. 1 item(s) deleted; 0 detached item(s) kept.');
    $this->assertSession()->pageTextContains('This site does not receive from any origin yet.');
    $this->assertNull($this->container->get('entity.repository')->loadEntityByUuid('node', $this->uuid));
    $notices = $this->container->get('state')->get(FakeOrigin::STATE)['notices'] ?? [];
    $this->assertSame('unpair_notice', end($notices)['type']);
  }

  /**
   * Unpairing from Drush says what happens to the content.
   */
  public function testDrushUnpair(): void {
    $this->drush('content-relay:replica-unpair', ['asia'], [], NULL, NULL, 1);
    $this->assertStringContainsString('--keep or --delete', $this->getErrorOutput());
    $this->drush('content-relay:replica-unpair', ['nowhere'], ['keep' => NULL], NULL, NULL, 1);
    $this->assertStringContainsString('There is no origin pairing "nowhere"', $this->getErrorOutput());
    $this->drush('content-relay:replica-unpair', ['default'], ['keep' => NULL]);
    $this->assertStringContainsString('Unpaired default: 0 item(s) deleted, 1 kept as content of this site.', $this->getErrorOutput());
    $this->assertNotNull($this->container->get('entity.repository')->loadEntityByUuid('node', $this->uuid));
    $this->container->get('state')->resetCache();
    $this->assertSame(['asia'], $this->container->get('content_relay_replica.active_pairing')->ids());
  }

  /**
   * A pairing as saved.
   */
  private function pairing(string $id): ContentRelayOriginPairing {
    $this->container->get('entity_type.manager')->getStorage('content_relay_origin_pairing')->resetCache([$id]);
    $pairing = ContentRelayOriginPairing::load($id);
    $this->assertInstanceOf(ContentRelayOriginPairing::class, $pairing);
    return $pairing;
  }

}
