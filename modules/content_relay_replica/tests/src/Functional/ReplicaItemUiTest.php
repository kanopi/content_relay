<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\file\Entity\File;
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
  protected static $modules = ['content_relay_replica', 'content_relay_test', 'file', 'node', 'views'];

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
   * A title over 255 characters makes it fail to save here.
   *
   * @return string
   *   Its UUID.
   */
  private function receive(string $title, bool $expect_success = TRUE): string {
    $node = Node::create(['type' => 'article', 'title' => mb_substr($title, 0, 255), 'uid' => 0]);
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node)), TRUE);
    $node->delete();
    // Longer than a title can be here: fails validation.
    $snapshot['shared']['title'][0]['value'] = $title;
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
    if ($expect_success) {
      $this->assertSame('applied', $result->result, (string) $result->message);
    }
    return $snapshot['uuid'];
  }

  /**
   * The item's sync status.
   */
  private function syncStatus(string $uuid, string $entity_type_id = 'node'): string {
    return $this->container->get('content_relay_replica.sync_state')->get($entity_type_id, $uuid)['status'];
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

    // Each page needs its permission.
    $pages = [
      'admin/reports/content-relay' => 'view content relay replica report',
      'admin/reports/content-relay/failures' => 'view content relay replica report',
      'admin/reports/content-relay/reconciliation' => 'reconcile content relay items',
      'admin/config/services/content-relay/replica/mappings' => 'administer content relay mappings',
      'admin/config/services/content-relay/replica/settings' => 'administer content relay replica',
      "admin/content/content-relay/node/{$this->uuid}/pull" => 'pull content relay items',
    ];
    $all = array_unique(array_values($pages));
    foreach ($pages as $path => $permission) {
      $this->drupalLogin($this->drupalCreateUser(array_values(array_diff($all, [$permission]))));
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
    // Resolving a conflict needs its own permission, on top of pulling.
    $this->editLocally($this->uuid, 'Edited here');
    $this->drupalLogin($this->drupalCreateUser(['pull content relay items']));
    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/pull");
    $this->assertSession()->buttonNotExists('Accept origin version');
    $this->drupalLogin($this->drupalCreateUser(['pull content relay items', 'resolve content relay conflicts']));
    $this->drupalGet("admin/content/content-relay/node/{$this->uuid}/pull");
    $this->assertSession()->buttonExists('Accept origin version');
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

  /**
   * Applies a file as the fake origin would send it, and serves it there.
   *
   * @return string
   *   Its UUID.
   */
  private function receiveFile(string $name, string $content): string {
    $uri = 'public://' . $name;
    file_put_contents($uri, $content);
    $file = File::create(['uri' => $uri, 'filename' => $name, 'status' => 1, 'uid' => 0]);
    $file->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($file)), TRUE);
    $file->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    $this->container->get('content_relay.file_checksums')->forget($uri);
    $state = $this->container->get('state');
    $config = $state->get(FakeOrigin::STATE);
    $config['files'][$snapshot['uuid']] = ['content' => $content, 'uri' => $uri];
    $state->set(FakeOrigin::STATE, $config);
    $event = SyncEvent::fromArray([
      'v' => SyncEvent::SCHEMA_VERSION,
      'event_id' => (new PhpUuid())->generate(),
      'sequence' => 1,
      'previous_sequence' => NULL,
      'operation' => 'create',
      'entity_type' => 'file',
      'bundle' => 'file',
      'entity_uuid' => $snapshot['uuid'],
      'source_revision' => 1,
      'revisionable' => FALSE,
      'source_changed' => 1700000000,
      'default_langcode' => $snapshot['default_langcode'],
      'langcodes' => array_keys($snapshot['translations']),
      'hash' => $this->container->get('content_relay.revision_hasher')->hash($snapshot)->toArray(),
      'contract_version' => 1,
      'created' => 1700000000,
      'dependencies' => [],
      'external_references' => [],
      'files' => [FakeOrigin::fileMeta($snapshot['uuid'], $content, $uri)],
      'process' => NULL,
      'snapshot' => $snapshot,
    ]);
    $this->container->get('content_relay_replica.applier')->apply($event, FakeOrigin::SITE_ID);
    return $snapshot['uuid'];
  }

  /**
   * Pulling a file from its page and with Drush.
   */
  public function testPullFile(): void {
    $uuid = $this->receiveFile('pulled.pdf', 'pulled bytes');
    $this->assertSame(FileSync::PENDING, $this->container->get('content_relay_replica.file_sync')->get($uuid)['status']);

    $this->drupalLogin($this->drupalCreateUser(['pull content relay items', 'view content relay replica report']));
    $this->drupalGet("admin/content/content-relay/file/$uuid/pull-files");
    $this->assertSession()->pageTextContains('Pull pulled.pdf from the origin again?');
    $this->submitForm([], 'Pull');
    $this->assertSession()->pageTextContains('Fetched 1 file.');
    $this->assertSame('pulled bytes', file_get_contents('public://pulled.pdf'));
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus($uuid, 'file'));

    // Drush, after the copy here was lost.
    unlink('public://pulled.pdf');
    $this->drush('content-relay:pull-missing-files', [], ['all' => NULL]);
    $this->assertSame('pulled bytes', file_get_contents('public://pulled.pdf'));
    $this->drush('content-relay:pull-file', [$uuid]);
    $this->drush('content-relay:pull-file', ['9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01'], [], NULL, NULL, 1);

    // Without the permission.
    $this->drupalLogin($this->drupalCreateUser(['view content relay replica report']));
    $this->drupalGet("admin/content/content-relay/file/$uuid/pull-files");
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Save failures: the admin message, the list, details, retry and dismiss.
   */
  public function testFailures(): void {
    $this->receive(str_repeat('Too long ', 40), FALSE);
    $this->drupalLogin($this->drupalCreateUser(['view content relay replica report', 'pull content relay items']));
    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->pageTextContains('1 synced item failed to save.');

    $this->drupalGet('admin/reports/content-relay/failures');
    $this->assertSession()->pageTextContains('validation_failed');
    $this->clickLink(mb_substr(str_repeat('Too long ', 40), 0, 20), 0);
    $this->assertSession()->pageTextContains('Field values that failed validation');
    $this->assertSession()->pageTextContains('title');

    // Retrying without fixing anything fails again.
    $this->submitForm([], 'Retry');
    $this->assertSession()->pageTextContains('could not be applied');

    $this->submitForm([], 'Dismiss');
    $this->assertSession()->pageTextContains('Say why it is dismissed.');
    $this->submitForm(['reason' => 'Titles are short here'], 'Dismiss');
    $this->assertSession()->pageTextContains('Dismissed. The item stays out of sync.');
    $this->assertSession()->pageTextContains('No failures match.');
    $failure = $this->container->get('content_relay_replica.failed_events')->find(['status' => 'dismissed'])[0];
    $this->assertSame('Titles are short here', $failure['dismiss_reason']);
    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->pageTextNotContains('failed to save');

    // In bulk, through a batch.
    $this->receive(str_repeat('Also too long ', 30), FALSE);
    $this->drupalGet('admin/reports/content-relay/failures');
    $this->submitForm([], 'Retry all matching the filters');
    $this->assertSession()->pageTextContains('0 applied, 1 still failing');

    // And from Drush.
    $this->drush('content-relay:failures');
    $this->assertStringContainsString('validation_failed', $this->getOutput());
    $id = $this->container->get('content_relay_replica.failed_events')->find()[0]['id'];
    $this->drush('content-relay:retry', [(string) $id], [], NULL, NULL, 1);
    $this->drush('content-relay:dismiss', [(string) $id], [], NULL, NULL, 1);
    $this->drush('content-relay:dismiss', [(string) $id], ['reason' => 'Known']);
    $this->assertSame([], $this->container->get('content_relay_replica.failed_events')->find());
  }

}
