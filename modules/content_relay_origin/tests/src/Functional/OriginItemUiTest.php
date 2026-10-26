<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Functional;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\Routing\ItemRoutes;
use Drupal\content_relay_test\FakeReplicaEndpoint;
use Drupal\node\NodeInterface;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use Drupal\views\Entity\View;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pushing, not syncable flags and the status report through the UI.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class OriginItemUiTest extends BrowserTestBase {

  use DrushTestTrait;
  use FileKeyTrait;

  private const REPLICA = '5d7f3c2a-1b4e-4f6a-8c9d-0e1f2a3b4c5d';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'block_content',
    'content_relay_origin',
    'content_relay_test',
    'node',
    'path',
    'taxonomy',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->drupalCreateContentType(['type' => 'private', 'name' => 'Private']);
    $this->createFileKey('origin_signing');
    $this->config('content_relay_origin.settings')->set('bundles', ['node' => ['article', 'page']])->save();
    $this->container->get('content_relay_origin.replica_registry')->save(new Replica(self::REPLICA, 'Mirror', 'https://' . FakeReplicaEndpoint::HOST . '/content-relay/v1/events', Replica::ACTIVE, []));
    $contracts = $this->container->get('content_relay_origin.contract_manager');
    $contracts->propose(self::REPLICA, ['bundles' => ['node' => ['article', 'page']]], NULL, 'replica');
    $contracts->acknowledge(self::REPLICA, 1);
    $this->container->get('state')->set(FakeReplicaEndpoint::STATE, ['responses' => [], 'events' => []]);
    $this->drupalPlaceBlock('local_tasks_block');
  }

  /**
   * Events the fake replica received.
   */
  private function received(): array {
    $this->container->get('state')->resetCache();
    return $this->container->get('state')->get(FakeReplicaEndpoint::STATE)['events'] ?? [];
  }

  /**
   * Outbox rows for an item.
   *
   * @phpstan-impure
   */
  private function outbox(NodeInterface $node): array {
    $rows = $this->container->get('database')->select(Outbox::TABLE, 'o')
      ->fields('o')
      ->condition('entity_uuid', $node->uuid())
      ->orderBy('sequence')
      ->execute()
      ->fetchAll();
    return array_map(fn (object $row) => (array) $row, $rows);
  }

  /**
   * The push tab and form, with the outcome shown inline.
   */
  public function testPushForm(): void {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Pushed']);
    $editor = $this->drupalCreateUser(['access content', 'push content relay items']);
    $this->drupalLogin($editor);

    $this->drupalGet($node->toUrl());
    $this->assertSession()->linkExists('Content Relay');
    $this->clickLink('Content Relay');
    $this->assertSession()->addressEquals("node/{$node->id()}/content-relay/push");
    $this->assertSession()->pageTextContains('Mirror');
    $this->assertSession()->checkboxChecked('replicas[' . self::REPLICA . '][select]');

    $this->submitForm(['force' => TRUE, 'mode' => 'queued'], 'Push now');
    $this->assertSession()->pageTextContains('Push result');
    $this->assertSession()->pageTextContains('Applied');
    $events = $this->received();
    $this->assertSame('queued', end($events)['process']);
    $rows = $this->outbox($node);
    $this->assertSame((string) $editor->id(), end($rows)['triggered_by']);
    $this->assertSame(Outbox::REASON_MANUAL, end($rows)['reason']);

    // A replica failing is reported, not hidden.
    $state = $this->container->get('state');
    $state->set(FakeReplicaEndpoint::STATE, [
      'responses' => [[422, ['result' => 'apply_failed', 'code' => 'validation_failed']]],
    ] + $state->get(FakeReplicaEndpoint::STATE));
    $this->submitForm(['force' => TRUE], 'Push now');
    $this->assertSession()->pageTextContains('Failed on the replica (validation_failed)');

    // Unpublished bundles have no push page.
    $private = $this->drupalCreateNode(['type' => 'private']);
    $this->drupalGet("node/{$private->id()}/content-relay/push");
    $this->assertSession()->statusCodeEquals(403);

    // Without the permission there is neither tab nor page.
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $this->drupalGet($node->toUrl());
    $this->assertSession()->linkNotExists('Content Relay');
    $this->drupalGet("node/{$node->id()}/content-relay/push");
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Not syncable from the entity form, the item page and the bulk action.
   */
  public function testNotSyncable(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access content overview',
      'bypass node access',
      'push content relay items',
      'mark content relay items not syncable',
    ]));

    // A new item marked on its form never produces an event.
    $this->drupalGet('node/add/article');
    $this->submitForm(['title[0][value]' => 'Secret', 'content_relay[not_syncable]' => TRUE], 'Save');
    $secret = $this->drupalGetNodeByTitle('Secret');
    $this->assertNotEmpty($this->container->get('content_relay_origin.not_syncable')->get('node', $secret->uuid()));
    $this->assertCount(0, $this->outbox($secret));

    $this->drupalGet("node/{$secret->id()}/content-relay/push");
    $this->assertSession()->pageTextContains('Not syncable: not pushed');
    $this->assertSession()->buttonExists('Push now');
    $this->assertSession()->elementAttributeExists('css', '#edit-submit', 'disabled');

    // Unmarking it on its form sends it.
    $this->drupalGet($secret->toUrl('edit-form'));
    $this->assertSession()->checkboxChecked('content_relay[not_syncable]');
    $this->submitForm(['content_relay[not_syncable]' => FALSE], 'Save');
    $this->assertSame(['attach', 'update', 'update'], array_column($this->outbox($secret), 'operation'));

    // Operation links, and the item page for a scoped flag.
    $synced = $this->drupalCreateNode(['type' => 'article', 'title' => 'Synced']);
    $this->drupalGet('admin/content');
    $this->assertSession()->linkByHrefExists("node/{$synced->id()}/content-relay/push");
    $this->assertSession()->linkByHrefExists("node/{$synced->id()}/content-relay/not-syncable");
    $this->drupalGet("node/{$synced->id()}/content-relay/not-syncable");
    $this->submitForm([
      'not_syncable' => TRUE,
      'scope' => 'selected',
      'replicas[' . self::REPLICA . ']' => TRUE,
      'behavior' => NotSyncableFlags::REMOVE,
    ], 'Save');
    $flags = $this->container->get('content_relay_origin.not_syncable')->get('node', $synced->uuid());
    $this->assertSame([self::REPLICA], array_keys($flags));
    $this->assertSame(NotSyncableFlags::REMOVE, $flags[self::REPLICA]['behavior']);

    // The bulk action.
    $other = $this->drupalCreateNode(['type' => 'article', 'title' => 'Bulk']);
    $this->drupalGet('admin/content', ['query' => ['title' => 'Bulk']]);
    $this->submitForm(['node_bulk_form[0]' => TRUE, 'action' => 'content_relay_node_not_syncable'], 'Apply to selected items');
    $this->assertNotEmpty($this->container->get('content_relay_origin.not_syncable')->get('node', $other->uuid()));
  }

  /**
   * The report, bulk push of a selection and of everything filtered, CSV.
   */
  public function testReportAndBulkPush(): void {
    $one = $this->drupalCreateNode(['type' => 'article', 'title' => 'Report one']);
    $this->drupalCreateNode(['type' => 'article', 'title' => 'Report two']);
    $this->drupalCreateNode(['type' => 'page', 'title' => 'Report page']);
    $this->drupalLogin($this->drupalCreateUser(['view content relay status report', 'bulk push content relay items']));

    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->pageTextContains('Report one');
    $this->assertSession()->pageTextContains('Report page');
    $this->assertSession()->pageTextContains('Pending');

    // A selection.
    $this->submitForm(['content_relay_bulk_push[2]' => TRUE], 'Push selected');
    $this->assertSession()->pageTextContains('Push these items?');
    $this->assertSession()->pageTextContains('Mirror');
    $this->submitForm([], 'Push');
    $this->assertSession()->pageTextContains('Mirror: 1 queued.');

    // Everything the filters match.
    $this->drupalGet('admin/reports/content-relay', ['query' => ['bundle' => 'article']]);
    $this->assertSession()->pageTextNotContains('Report page');
    $this->clickLink('Push all matching filters');
    $this->submitForm([], 'Push');
    $this->assertSession()->pageTextContains('Mirror: 2 queued.');
    $reasons = array_count_values(array_filter($this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['reason'])->execute()->fetchCol()));
    $this->assertSame([Outbox::REASON_BULK => 3], $reasons);

    // CSV of the filtered report.
    $this->drupalGet('admin/reports/content-relay', ['query' => ['bundle' => 'article']]);
    $this->clickLink('Download CSV');
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $csv = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('Report one', $csv);
    $this->assertStringNotContainsString('Report page', $csv);

    // Refresh.
    $this->drupalGet('admin/reports/content-relay');
    $this->clickLink('Refresh status');
    // Clicking does not follow the batch page's meta refresh by itself.
    $this->checkForMetaRefresh();
    $this->assertSession()->pageTextContains('Sync status refreshed for 2 bundle(s).');

    // A site builder adds a column; bulk push still works.
    $view = View::load('content_relay_origin_report');
    $display = &$view->getDisplay('default');
    $display['display_options']['fields']['entity_id'] = [
      'id' => 'entity_id',
      'table' => 'content_relay_report',
      'field' => 'entity_id',
      'plugin_id' => 'standard',
      'label' => 'Local ID',
    ];
    $view->save();
    $this->drupalGet('admin/reports/content-relay', ['query' => ['search' => $one->uuid()]]);
    $this->assertSession()->pageTextContains('Local ID');
    $this->submitForm(['content_relay_bulk_push[0]' => TRUE], 'Push selected');
    $this->submitForm([], 'Push');
    $this->assertSession()->pageTextContains('Mirror: 1 queued.');

    // Without bulk push: no checkboxes, no push links, no confirm page.
    $this->drupalLogin($this->drupalCreateUser(['view content relay status report']));
    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->buttonNotExists('Push selected');
    $this->assertSession()->linkNotExists('Push all matching filters');
    $this->assertSession()->linkExists('Download CSV');
    $this->drupalGet('admin/reports/content-relay/push');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A site builder changes the report's filters; bulk push follows them.
   */
  public function testCustomizedFilters(): void {
    foreach (['Alpha one', 'Alpha two', 'Beta one'] as $title) {
      $this->drupalCreateNode(['type' => 'article', 'title' => $title]);
    }
    $this->drupalCreateNode(['type' => 'page', 'title' => 'Alpha page']);

    // The bundle filter is fixed to articles, and the label gets an exposed
    // "contains" filter of its own.
    $view = View::load('content_relay_origin_report');
    $display = &$view->getDisplay('default');
    $filters = &$display['display_options']['filters'];
    $filters['bundle']['exposed'] = FALSE;
    $filters['bundle']['value'] = ['article' => 'article'];
    $filters['label'] = [
      'id' => 'label',
      'table' => 'content_relay_report',
      'field' => 'label',
      'plugin_id' => 'string',
      'operator' => 'contains',
      'value' => '',
      'exposed' => TRUE,
      'expose' => [
        'operator_id' => 'label_op',
        'label' => 'Label contains',
        'identifier' => 'label_contains',
        'operator' => 'label_op',
        'required' => FALSE,
        'multiple' => FALSE,
        'remember' => FALSE,
      ],
    ];
    unset($filters);
    $view->save();

    $this->drupalLogin($this->drupalCreateUser(['view content relay status report', 'bulk push content relay items']));
    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->fieldNotExists('bundle');
    $this->assertSession()->pageTextNotContains('Alpha page');
    $this->assertSession()->pageTextContains('Beta one');

    $this->drupalGet('admin/reports/content-relay', ['query' => ['label_contains' => 'Alpha']]);
    $this->assertSession()->pageTextContains('Alpha two');
    $this->assertSession()->pageTextNotContains('Beta one');
    $this->assertSession()->pageTextNotContains('Alpha page');
    $this->clickLink('Push all matching filters');
    $this->submitForm([], 'Push');
    $this->assertSession()->pageTextContains('Mirror: 2 queued.');
    $pushed = $this->container->get('database')->select(Outbox::TABLE, 'o')
      ->fields('o', ['entity_uuid'])
      ->condition('reason', Outbox::REASON_BULK)
      ->execute()
      ->fetchCol();
    $titles = array_map(fn (string $uuid) => $this->container->get('entity.repository')->loadEntityByUuid('node', $uuid)->label(), $pushed);
    sort($titles);
    $this->assertSame(['Alpha one', 'Alpha two'], $titles);

    // A selection still works, as does the CSV, with the same filters.
    $this->drupalGet('admin/reports/content-relay', ['query' => ['label_contains' => 'Beta']]);
    $this->submitForm(['content_relay_bulk_push[0]' => TRUE], 'Push selected');
    $this->submitForm([], 'Push');
    $this->assertSession()->pageTextContains('Mirror: 1 queued.');
    $this->drupalGet('admin/reports/content-relay', ['query' => ['label_contains' => 'Alpha']]);
    $this->clickLink('Download CSV');
    $csv = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('Alpha one', $csv);
    $this->assertStringNotContainsString('Beta one', $csv);
    $this->assertStringNotContainsString('Alpha page', $csv);
  }

  /**
   * Drush commands, with exit codes.
   */
  public function testDrush(): void {
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Drushed']);
    $this->drush('content-relay:push', ['node', (string) $node->id()], ['force' => NULL]);
    $this->assertStringContainsString('Applied', $this->getOutput());

    $this->drush('content-relay:push', ['node', '999999'], [], NULL, NULL, 1);
    $this->drush('content-relay:not-syncable', ['node', $node->uuid()], ['behavior' => 'detach']);
    $this->assertNotEmpty($this->container->get('content_relay_origin.not_syncable')->get('node', $node->uuid()));
    $this->drush('content-relay:push', ['node', (string) $node->id()], [], NULL, NULL, 1);
    $this->drush('content-relay:not-syncable', ['node', (string) $node->id()], ['behavior' => 'sideways'], NULL, NULL, 1);
    $this->drush('content-relay:syncable', ['node', (string) $node->id()]);
    $this->assertSame([], $this->container->get('content_relay_origin.not_syncable')->get('node', $node->uuid()));

    $this->drupalCreateNode(['type' => 'page', 'title' => 'Drushed page']);
    $this->drush('content-relay:bulk-push', [], ['bundle' => 'page', 'dry-run' => NULL]);
    $this->assertStringContainsString(self::REPLICA . ': 1 to push', $this->getOutput());
  }

  /**
   * Push for other entity types: tab, operation link, form and Drush.
   */
  public function testOtherEntityTypes(): void {
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic'])->save();
    $bundles = [
      'node' => ['article', 'page'],
      'taxonomy_term' => ['tags'],
      'block_content' => ['basic'],
      'path_alias' => ['path_alias'],
    ];
    $this->config('content_relay_origin.settings')->set('bundles', $bundles)->save();
    $contracts = $this->container->get('content_relay_origin.contract_manager');
    $contracts->propose(self::REPLICA, ['bundles' => $bundles], NULL, 'replica');
    $contracts->acknowledge(self::REPLICA, 2);

    // Every syncable type has the item pages.
    $routes = $this->container->get('router.route_provider');
    $types = array_keys($this->container->get('content_relay.syncable_entity_types')->getEntityTypes());
    $this->assertContains('block_content', $types);
    foreach ($types as $entity_type_id) {
      foreach (['push' => 'push', 'not_syncable' => 'not-syncable', 'validate' => 'validate'] as $page => $path) {
        $this->assertStringEndsWith("/content-relay/$path", $routes->getRouteByName(ItemRoutes::name($entity_type_id, $page))->getPath());
      }
    }

    $term = Term::create(['vid' => 'tags', 'name' => 'Pushed term']);
    $term->save();
    $block = BlockContent::create(['type' => 'basic', 'info' => 'Pushed block']);
    $block->save();
    $alias = PathAlias::create(['path' => '/taxonomy/term/' . $term->id(), 'alias' => '/pushed']);
    $alias->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'access taxonomy overview',
      'administer taxonomy',
      'access block library',
      'administer block content',
      'administer url aliases',
      'push content relay items',
    ]));

    // A term: the tab on its page, and the operation on its vocabulary.
    $this->drupalGet('admin/structure/taxonomy/manage/tags/overview');
    $this->assertSession()->linkByHrefExists("taxonomy/term/{$term->id()}/content-relay/push");
    $this->drupalGet($term->toUrl());
    $this->clickLink('Content Relay');
    $this->assertSession()->addressEquals("taxonomy/term/{$term->id()}/content-relay/push");
    $this->submitForm(['force' => TRUE], 'Push now');
    $this->assertSession()->pageTextContains('Applied');
    $events = $this->received();
    $this->assertSame(['taxonomy_term', $term->uuid()], [end($events)['entity_type'], end($events)['entity_uuid']]);

    // A block, from the block library.
    $push = "admin/content/block/{$block->id()}/content-relay/push";
    $this->drupalGet('admin/content/block');
    $this->assertSession()->linkByHrefExists($push);
    $this->drupalGet($push);
    $this->submitForm(['force' => TRUE], 'Push now');
    $this->assertSession()->pageTextContains('Applied');
    $events = $this->received();
    $this->assertSame(['block_content', $block->uuid()], [end($events)['entity_type'], end($events)['entity_uuid']]);

    // A path alias, which has no page of its own.
    $push = "admin/content/content-relay/item/path_alias/{$alias->id()}/content-relay/push";
    $this->drupalGet('admin/config/search/path');
    $this->assertSession()->linkByHrefExists($push);
    $this->drupalGet($push);
    $this->submitForm(['force' => TRUE], 'Push now');
    $this->assertSession()->pageTextContains('Applied');
    $events = $this->received();
    $this->assertSame(['path_alias', $alias->uuid()], [end($events)['entity_type'], end($events)['entity_uuid']]);

    // Drush, by ID and by UUID.
    $this->drush('content-relay:push', ['taxonomy_term', (string) $term->id()], ['force' => NULL]);
    $this->assertStringContainsString('Applied', $this->getOutput());
    $this->drush('content-relay:push', ['block_content', $block->uuid()], ['force' => NULL]);
    $this->assertStringContainsString('Applied', $this->getOutput());
    $events = $this->received();
    $this->assertSame(['taxonomy_term', 'block_content'], array_column(array_slice($events, -2), 'entity_type'));
    $this->drush('content-relay:push', ['taxonomy_term', '999999'], [], NULL, NULL, 1);
  }

  /**
   * Requiring encryption in settings sends replicas a new contract.
   */
  public function testRequireEncryptionSetting(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer content relay origin']));
    $this->drupalGet('admin/config/services/content-relay/origin');
    $this->submitForm(['encryption[require]' => TRUE, 'encryption[transition_window]' => 30], 'Save configuration');
    $this->assertSession()->pageTextContains('Replicas will be sent new contract versions with the encryption policy.');
    $config = $this->config('content_relay.settings');
    $this->assertTrue($config->get('encryption.require'));
    $this->assertSame(1800, $config->get('encryption.transition_window'));
    $pending = $this->container->get('content_relay_origin.contract_manager')->getPending(self::REPLICA);
    $this->assertSame(2, $pending->version());
    $this->assertTrue($pending->terms['joint']['require_encryption']['origin']);
    // Neither side has an encryption key here, so it is said, not granted.
    $this->assertSame('none', $pending->encryption());
    $this->assertSame('encryption', $pending->notGranted()[0]['term']);
  }

  /**
   * The event log lists events, shows one, and replays a failed one.
   */
  public function testEventLogAndReplay(): void {
    // The replica refuses the first event for good.
    $this->container->get('state')->set(FakeReplicaEndpoint::STATE, [
      'responses' => [[400, ['result' => 'rejected']]],
      'events' => [],
    ]);
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Refused once']);
    $this->container->get('content_relay_origin.delivery')->deliver(self::REPLICA);
    $row = $this->outbox($node)[0];
    $this->assertSame('dead_letter', $row['status']);

    $this->drupalLogin($this->drupalCreateUser(['view content relay event log']));
    $this->drupalGet('admin/reports/content-relay/events');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($node->uuid());
    $this->assertSession()->linkExists('Details');
    $this->assertSession()->linkNotExists('Replay');
    $this->drupalGet('admin/reports/content-relay/events/' . $row['id']);
    $this->assertSession()->pageTextContains('Dead-lettered');
    $this->assertSession()->pageTextContains('rejected');
    $this->drupalGet('admin/reports/content-relay/events/' . $row['id'] . '/replay');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view content relay event log', 'replay content relay events']));
    $this->drupalGet('admin/reports/content-relay/events');
    $this->clickLink('Replay');
    $this->submitForm([], 'Replay');
    $this->assertSession()->pageTextContains('Replayed as event log entry');
    $rows = $this->outbox($node);
    $this->assertSame('replayed', $rows[0]['status']);
    $this->assertSame((string) $row['id'], (string) $rows[1]['replay_of']);
    $this->assertSame((string) $this->loggedInUser->id(), (string) $rows[1]['triggered_by']);

    // Only failed entries can be replayed.
    $this->drupalGet('admin/reports/content-relay/events/' . $rows[1]['id'] . '/replay');
    $this->assertSame(404, $this->getSession()->getStatusCode());
  }

  /**
   * More Drush commands, with exit codes.
   */
  public function testMoreDrush(): void {
    $this->drush('content-relay:replicas');
    $this->assertStringContainsString('Mirror', $this->getOutput());
    $this->drush('content-relay:pairing-code', [], ['bundles' => 'node:article']);
    $this->assertMatchesRegularExpression('/[A-Z0-9-]{8,}/', $this->getOutput());
    $this->drush('content-relay:pairing-code', [], ['bundles' => 'article'], NULL, NULL, 1);

    $this->container->get('state')->set(FakeReplicaEndpoint::STATE, [
      'responses' => [[400, ['result' => 'rejected']]],
      'events' => [],
    ]);
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Will fail']);
    $this->container->get('content_relay_origin.delivery')->deliver(self::REPLICA);
    // Dead letters make the status command fail.
    $this->drush('content-relay:origin-status', [], [], NULL, NULL, 1);
    $this->assertStringContainsString('dead_letter', $this->getOutput());
    $this->drush('content-relay:replay', [(string) $this->outbox($node)[0]['id']]);
    $this->drush('content-relay:replay', ['999999'], [], NULL, NULL, 1);
    $this->drush('content-relay:backfill-hashes');
    $this->drush('content-relay:origin-reconcile');

    // Validate: a replica that cannot answer fails the command, an item kept
    // from every replica does not.
    $checked = $this->drupalCreateNode(['type' => 'article', 'title' => 'Checked']);
    $this->drush('content-relay:origin-validate', ['node', (string) $checked->id()], [], NULL, NULL, 1);
    $this->assertStringContainsString('Mirror (' . self::REPLICA . '): Could not be asked', $this->getOutput());
    $this->drush('content-relay:not-syncable', ['node', $checked->uuid()]);
    $this->drush('content-relay:origin-validate', ['node', $checked->uuid()]);
    $this->assertStringContainsString('Mirror (' . self::REPLICA . '): Not syncable', $this->getOutput());
    $this->drush('content-relay:origin-validate', ['node', '999999'], [], NULL, NULL, 1);
  }

}
