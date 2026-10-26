<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use Drupal\user\RoleInterface;
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
   * An article event as the fake origin would send it.
   *
   * @param string $title
   *   The title; over 255 characters, it fails validation here.
   * @param string|null $uuid
   *   The UUID of an item this site has, for a later version of it.
   * @param int $revision
   *   The origin revision.
   * @param array $values
   *   Other field values.
   */
  private function articleEvent(string $title, ?string $uuid = NULL, int $revision = 1, array $values = []): SyncEvent {
    $node = Node::create($values + ['type' => 'article', 'title' => mb_substr($title, 0, 255), 'uid' => 0]);
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node)), TRUE);
    $node->delete();
    $snapshot['shared']['title'][0]['value'] = $title;
    $snapshot['uuid'] = $uuid ?? $snapshot['uuid'];
    $this->container->get('content_relay.uuid_resolver')->reset();
    return SyncEvent::fromArray([
      'v' => SyncEvent::SCHEMA_VERSION,
      'event_id' => (new PhpUuid())->generate(),
      'sequence' => $revision,
      'previous_sequence' => $revision > 1 ? $revision - 1 : NULL,
      'operation' => $uuid === NULL ? 'create' : 'update',
      'entity_type' => 'node',
      'bundle' => 'article',
      'entity_uuid' => $snapshot['uuid'],
      'source_revision' => $revision,
      'revisionable' => TRUE,
      'source_changed' => 1700000000 + $revision,
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
    $event = $this->articleEvent($title);
    $result = $this->container->get('content_relay_replica.applier')->apply($event, FakeOrigin::SITE_ID);
    if ($expect_success) {
      $this->assertSame('applied', $result->result, (string) $result->message);
    }
    return $event->entityUuid;
  }

  /**
   * Has the fake origin serve an event from its item endpoint.
   */
  private function serve(SyncEvent $event): void {
    $this->updateFakeOrigin(function (array &$config) use ($event): void {
      $config['items']['node:' . $event->entityUuid] = $event->toArray();
    });
  }

  /**
   * Changes what the fake origin knows.
   */
  private function updateFakeOrigin(callable $change): void {
    $state = $this->container->get('state');
    $state->resetCache();
    $config = $state->get(FakeOrigin::STATE);
    $change($config);
    $state->set(FakeOrigin::STATE, $config);
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
   * The pull form: straight pull, then a diff and a choice over local edits.
   */
  public function testPullForm(): void {
    $title = function (): string {
      $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
      return (string) $this->container->get('entity.repository')->loadEntityByUuid('node', $this->uuid)->label();
    };
    $pull = "admin/content/content-relay/node/{$this->uuid}/pull";
    $resolver = $this->drupalCreateUser([
      'access content',
      'pull content relay items',
      'resolve content relay conflicts',
      'view content relay replica report',
    ]);
    $this->drupalLogin($resolver);

    // Not edited here: pulled without a question.
    $this->serve($this->articleEvent('Second version', $this->uuid, 2));
    $this->drupalGet($pull);
    $this->assertSession()->pageTextNotContains('What differs');
    $this->submitForm([], 'Pull latest from origin');
    $this->assertSession()->pageTextContains("The origin's latest version was applied.");
    $this->assertSame('Second version', $title());

    // Edited here: the diff, and nothing changes until a choice is made.
    $this->editLocally($this->uuid, 'Edited here');
    $this->serve($this->articleEvent('Third version', $this->uuid, 3));
    $this->drupalGet($pull);
    $this->assertSession()->pageTextContains('Resolve Edited here');
    $this->assertSession()->pageTextContains('What differs');
    $cells = $this->getSession()->getPage()->findAll('css', 'table td pre');
    $this->assertSame(['Edited here', 'Third version'], array_map(fn ($cell) => $cell->getText(), $cells));
    $this->assertSame('Edited here', $title());
    $this->submitForm([], 'Accept origin version');
    $this->assertSame('Third version', $title());
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus($this->uuid));

    // Without the permission to resolve, the diff is shown but not the
    // choice.
    $this->editLocally($this->uuid, 'Edited again');
    $this->serve($this->articleEvent('Fourth version', $this->uuid, 4));
    $this->drupalLogin($this->drupalCreateUser(['access content', 'pull content relay items']));
    $this->drupalGet($pull);
    $this->assertSession()->pageTextContains('What differs');
    $this->assertSession()->buttonNotExists('Accept origin version');
    $this->assertSession()->buttonNotExists('Keep local and detach');

    // Or the local edit is kept, and the item detached.
    $this->drupalLogin($resolver);
    $this->drupalGet($pull);
    $this->submitForm([], 'Keep local and detach');
    $this->assertSession()->pageTextContains('keeps its local version and is detached');
    $this->assertSame('Edited again', $title());
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->syncStatus($this->uuid));
    $this->drupalGet($pull);
    $this->assertSession()->pageTextContains('This item is detached on this site');
    $this->assertSession()->buttonNotExists('Pull latest from origin');

    // Neither permission, no page.
    $this->drupalLogin($this->drupalCreateUser(['access content', 'view content relay replica report']));
    $this->drupalGet($pull);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * More Drush commands, with exit codes.
   *
   * Pull, validate, reconcile, fill gaps, schema, mapping and language.
   */
  public function testMoreDrush(): void {
    $title = fn () => $this->container->get('entity.repository')->loadEntityByUuid('node', $this->uuid)->label();
    $reset = fn () => $this->container->get('entity_type.manager')->getStorage('node')->resetCache();

    // Pull: the latest version; refused over local edits unless accepted.
    $this->serve($this->articleEvent('Pulled', $this->uuid, 2));
    $this->drush('content-relay:pull', ['node', $this->uuid]);
    $this->assertStringContainsString('applied', $this->getErrorOutput());
    $reset();
    $this->assertSame('Pulled', $title());
    $this->editLocally($this->uuid, 'Edited here');
    $this->serve($this->articleEvent('Pulled again', $this->uuid, 3));
    $this->config('content_relay_replica.settings')->set('conflicts.on_event', 'reject')->save();
    $this->drush('content-relay:pull', ['node', $this->uuid], [], NULL, NULL, 1);
    $this->assertStringContainsString('--accept-origin', $this->getErrorOutput());
    $this->drush('content-relay:pull', ['node', $this->uuid], ['accept-origin' => NULL]);
    $reset();
    $this->assertSame('Pulled again', $title());
    $this->drush('content-relay:pull', ['node', '9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01'], [], NULL, NULL, 1);

    // Validate: in sync with what the origin serves, then not.
    $this->drush('content-relay:validate', ['node', $this->uuid]);
    $this->assertStringContainsString('Origin: in sync at revision 3', $this->getErrorOutput());
    $this->serve($this->articleEvent('Newer on the origin', $this->uuid, 4));
    $this->drush('content-relay:validate', ['node', $this->uuid], [], NULL, NULL, 1);
    $this->assertStringContainsString('Origin: out of sync. The origin has revision 4, this site received 3', $this->getErrorOutput());
    $this->drush('content-relay:validate', ['node', '9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01'], [], NULL, NULL, 1);

    // Reconcile pulls what the hash listing says is newer.
    $event = $this->articleEvent('Newer on the origin', $this->uuid, 4);
    $this->updateFakeOrigin(function (array &$config) use ($event): void {
      $config['hashes'] = [
        'node:article' => [
          [
            'uuid' => $event->entityUuid,
            'revision' => 4,
            'changed' => 1700000004,
            'hash' => $event->hashes->toArray(),
            'not_syncable' => FALSE,
          ],
        ],
      ];
    });
    $this->drush('content-relay:reconcile');
    $this->assertStringContainsString('pulled: 1', $this->getErrorOutput());
    $reset();
    $this->assertSame('Newer on the origin', $title());
    $this->drush('content-relay:reconcile');
    $this->assertStringContainsString('in_sync: 1', $this->getErrorOutput());
    $this->updateFakeOrigin(function (array &$config): void {
      $config['down'] = TRUE;
    });
    $this->drush('content-relay:reconcile', [], [], NULL, NULL, 1);
    $this->drush('content-relay:check-schema', [], [], NULL, NULL, 1);
    $this->updateFakeOrigin(function (array &$config): void {
      $config['down'] = FALSE;
    });

    // Adopting: the synced item is skipped, and the command can run again.
    $this->drush('content-relay:adopt');
    $this->assertStringContainsString('already_synced: 1', $this->getErrorOutput());
    $this->updateFakeOrigin(function (array &$config): void {
      $config['down'] = TRUE;
    });
    $this->drush('content-relay:adopt', [], [], NULL, NULL, 1);
    $this->updateFakeOrigin(function (array &$config): void {
      $config['down'] = FALSE;
    });

    // Gaps: none to fill.
    $this->drush('content-relay:fill-gaps');
    $this->assertStringContainsString('Gaps filled', $this->getErrorOutput());

    // Schema: matching, then a bundle the origin no longer describes.
    $this->drush('content-relay:check-schema');
    $this->assertStringContainsString('node.article (as_is)', $this->getOutput());
    $this->updateFakeOrigin(function (array &$config): void {
      $config['schema'] = [];
    });
    $this->drush('content-relay:check-schema', [], [], NULL, NULL, 1);
    $this->assertStringContainsString('problem: The origin no longer describes this bundle.', $this->getOutput());
    $this->updateFakeOrigin(function (array &$config): void {
      unset($config['schema']);
    });

    // Re-applying a mapping pulls every item of its bundle again.
    $this->drush('content-relay:reapply-mapping', ['node__article'], [], NULL, NULL, 1);
    $this->container->get('entity_type.manager')->getStorage('content_relay_mapping')->create([
      'id' => 'node__article',
      'label' => 'Articles, titles only',
      'source_entity_type' => 'node',
      'source_bundle' => 'article',
      'target_entity_type' => 'node',
      'target_bundle' => 'article',
      'fields' => [['source' => 'title', 'target' => 'title', 'process' => []]],
      'ignore' => ['*'],
    ])->save();
    $this->drush('content-relay:reapply-mapping', ['node__article']);
    $this->assertStringContainsString('1 item(s) re-applied, 0 failed', $this->getErrorOutput());

    // Moving a language queues the work.
    $this->drush('content-relay:move-language', ['en', 'fr']);
    $this->assertStringContainsString('Queued', $this->getErrorOutput());
    $this->assertGreaterThan(0, $this->container->get('queue')->get(LanguageSubscription::QUEUE)->numberOfItems());
  }

  /**
   * Key commands: list, generate, rotate and retire, with exit codes.
   */
  public function testKeyDrush(): void {
    $this->drush('content-relay:keys', [], ['format' => 'json']);
    $keys = array_column(json_decode($this->getOutput(), TRUE), NULL, 'key');
    $this->assertSame(['signing', 'primary'], [$keys['replica_signing']['purpose'], $keys['replica_signing']['status']]);

    $path = sys_get_temp_dir() . '/content-relay-' . $this->randomMachineName() . '.key';
    $this->drush('content-relay:generate-key', ['signing', 'replica_signing_next', $path]);
    $this->assertFileExists($path);
    $this->drush('content-relay:keys', [], ['format' => 'json']);
    $keys = array_column(json_decode($this->getOutput(), TRUE), NULL, 'key');
    $this->assertSame('active', $keys['replica_signing_next']['status']);

    $this->drush('content-relay:rotate-key', ['signing', 'replica_signing_next']);
    $this->assertStringContainsString('replica_signing_next is now primary', $this->getErrorOutput());
    $this->drush('content-relay:retire-key', ['signing', 'replica_signing']);
    $this->assertStringContainsString('replica_signing is retired', $this->getErrorOutput());
    $this->drush('content-relay:keys', [], ['format' => 'json']);
    $keys = array_column(json_decode($this->getOutput(), TRUE), NULL, 'key');
    $this->assertSame(['retired', 'primary'], [
      $keys['replica_signing']['status'],
      $keys['replica_signing_next']['status'],
    ]);

    $this->drush('content-relay:rotate-key', ['signing', 'no_such_key'], [], NULL, NULL, 1);
    $this->drush('content-relay:retire-key', ['signing', 'replica_signing_next'], [], NULL, NULL, 1);
    @unlink($path);
  }

  /**
   * Applies a file as the fake origin would send it, and serves it there.
   *
   * @return string
   *   Its UUID.
   */
  private function receiveFile(string $name, string $content, string $scheme = 'public'): string {
    $uri = $scheme . '://' . $name;
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
   * A private file arrives private, and downloads follow content access.
   */
  public function testPrivateFiles(): void {
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_attachment',
      'type' => 'file',
      'settings' => ['uri_scheme' => 'private'],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_name' => 'field_attachment',
      'settings' => ['file_extensions' => 'pdf'],
    ])->save();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);

    $file_uuid = $this->receiveFile('report.pdf', 'private bytes', 'private');
    $this->drush('content-relay:pull-file', [$file_uuid]);
    $file = $this->container->get('entity.repository')->loadEntityByUuid('file', $file_uuid);
    $this->assertSame('private://report.pdf', $file->getFileUri());
    $this->assertSame('private bytes', file_get_contents('private://report.pdf'));
    $url = $file->createFileUrl(FALSE);
    $this->assertStringContainsString('/system/files/report.pdf', $url);

    // Not used by anything yet: nobody may download it.
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);

    // Attached to a published article, it is as visible as the article.
    $attachment = ['field_attachment' => [['target_id' => $file->id(), 'display' => 1]]];
    $event = $this->articleEvent('With attachment', NULL, 1, $attachment);
    $this->assertSame('applied', $this->container->get('content_relay_replica.applier')->apply($event, FakeOrigin::SITE_ID)->result);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('private bytes', $this->getSession()->getPage()->getContent());

    // Unpublished on the origin, it is not, except to whoever may see
    // unpublished content here.
    $unpublished = $this->articleEvent('With attachment', $event->entityUuid, 2, ['status' => 0] + $attachment);
    $this->assertSame('applied', $this->container->get('content_relay_replica.applier')->apply($unpublished, FakeOrigin::SITE_ID)->result);
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['access content', 'bypass node access']));
    $this->drupalGet($url);
    $this->assertSession()->statusCodeEquals(200);
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
