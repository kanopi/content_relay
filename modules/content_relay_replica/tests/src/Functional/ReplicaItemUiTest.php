<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests detaching and reattaching through the replica's pages and Drush.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReplicaItemUiTest extends BrowserTestBase {

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
   * UUID of the synced article.
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
    $this->uuid = $this->receive('From the origin');
  }

  /**
   * Applies an article as the fake origin would send it.
   *
   * @return string
   *   Its UUID.
   */
  private function receive(string $title): string {
    $node = Node::create(['type' => 'article', 'title' => $title, 'uid' => 0]);
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
      'source_changed' => 1700000000,
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
    return $snapshot['uuid'];
  }

  /**
   * The item's sync status.
   */
  private function syncStatus(string $uuid): string {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid)['status'];
  }

  /**
   * Notices the fake origin received.
   */
  private function notices(): array {
    $this->container->get('state')->resetCache();
    return array_column($this->container->get('state')->get(FakeOrigin::STATE)['notices'] ?? [], 'type');
  }

  /**
   * Edits the local copy.
   */
  private function editLocally(string $uuid, string $title): void {
    $this->container->get('entity.repository')->loadEntityByUuid('node', $uuid)->setTitle($title)->save();
  }

  /**
   * Detach, reattach with local edits, operations and the report.
   */
  public function testDetachAndReattach(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access content overview',
      'detach content relay items',
      'view content relay replica report',
    ]));

    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->pageTextContains('From the origin');
    $this->assertSession()->pageTextContains('Synced');

    $this->drupalGet('admin/content');
    $this->assertSession()->linkByHrefExists("admin/content/content-relay/node/{$this->uuid}/detach");
    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/detach");
    $this->submitForm([], 'Detach');
    $this->assertSession()->pageTextContains('From the origin is detached.');
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($this->uuid));
    $this->assertSame(['detach_notice'], $this->notices());

    // Edited while detached: the choice is offered.
    $this->editLocally($this->uuid, 'Edited here');
    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/reattach");
    $this->assertSession()->pageTextContains('This item was changed here since it was detached.');
    $this->submitForm([], 'Keep local and stay detached');
    $this->assertSession()->pageTextContains('stays detached with your changes');
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($this->uuid));

    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/reattach");
    $this->submitForm([], 'Accept origin version');
    $this->assertSession()->pageTextContains('is reattached');
    $this->assertSame(SyncStatus::ERROR, $this->syncStatus($this->uuid));
    $this->assertSame(['detach_notice', 'attach_notice'], $this->notices());

    // Not detached any more: no reattach page.
    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/reattach");
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Bulk detach and reattach from the report, through the Batch API.
   */
  public function testBulkDetach(): void {
    $second = $this->receive('Second');
    $this->drupalLogin($this->drupalCreateUser(['detach content relay items', 'view content relay replica report']));
    $this->drupalGet('admin/reports/content-relay');
    $this->submitForm([
      'content_relay_bulk_detach[0]' => TRUE,
      'content_relay_bulk_detach[1]' => TRUE,
      'operation' => 'detach',
    ], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Detach 2 items?');
    $this->submitForm([], 'Confirm');
    $this->assertSession()->pageTextContains('2 item(s) detached, 0 unchanged.');
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($second));

    $this->editLocally($second, 'Changed');
    $this->drupalGet('admin/reports/content-relay');
    $this->submitForm([
      'content_relay_bulk_detach[0]' => TRUE,
      'content_relay_bulk_detach[1]' => TRUE,
      'operation' => 'reattach',
    ], 'Apply to selected items');
    $this->submitForm([], 'Confirm');
    // The edited one stays detached without "replace local changes".
    $this->assertSession()->pageTextContains('1 item(s) reattached, 1 unchanged.');
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($second));
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus($this->uuid));
  }

  /**
   * Without the permission, nothing to detach with.
   */
  public function testPermission(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access content overview',
      'view content relay replica report',
    ]));
    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/detach");
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('admin/content');
    $this->assertSession()->linkByHrefNotExists("admin/content/content-relay/node/{$this->uuid}/detach");
    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->buttonNotExists('Apply to selected items');
  }

  /**
   * Drush commands, with exit codes.
   */
  public function testDrush(): void {
    $this->drush('content-relay:detach', ['node', $this->uuid]);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($this->uuid));
    $this->drush('content-relay:detach', ['node', '9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01'], [], NULL, NULL, 1);

    $this->editLocally($this->uuid, 'Changed by hand');
    $this->drush('content-relay:reattach', ['node', $this->uuid], [], NULL, NULL, 1);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($this->uuid));
    $this->drush('content-relay:reattach', ['node', $this->uuid], ['accept-origin' => NULL]);
    $this->assertSame(SyncStatus::ERROR, $this->syncStatus($this->uuid));
    $this->drush('content-relay:reattach', ['node', $this->uuid], [], NULL, NULL, 1);
  }

}
