<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Functional;

use Drupal\content_relay_origin\Replica;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the origin's administration pages.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class OriginAdminTest extends BrowserTestBase {

  use FileKeyTrait;

  private const REPLICA = '5d7f3c2a-1b4e-4f6a-8c9d-0e1f2a3b4c5d';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_relay_origin', 'node'];

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
    $this->createFileKey('origin_signing');
    $this->drupalLogin($this->drupalCreateUser([
      'administer content relay origin',
      'administer content relay replicas',
      'generate content relay pairing codes',
    ]));
  }

  /**
   * Registers a replica that has acknowledged contract version 1.
   */
  private function registerReplica(string $status = Replica::ACTIVE): void {
    $this->config('content_relay_origin.settings')->set('bundles', ['node' => ['article', 'page']])->save();
    $this->container->get('content_relay_origin.replica_registry')->save(new Replica(self::REPLICA, 'China', 'https://replica.example.com/content-relay/v1/events', $status, []));
    $contracts = $this->container->get('content_relay_origin.contract_manager');
    $contracts->propose(self::REPLICA, ['bundles' => ['node' => ['article', 'page']]], NULL, 'replica');
    $contracts->acknowledge(self::REPLICA, 1);
  }

  /**
   * The settings form publishes bundles and enforces the code length range.
   */
  public function testSettings(): void {
    $this->drupalGet('admin/config/services/content-relay/origin');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm(['pairing[code_length]' => 7], 'Save configuration');
    $this->assertSession()->pageTextContains('Pairing codes must be between 8 and 64 characters.');
    $this->submitForm(['pairing[code_length]' => 65], 'Save configuration');
    $this->assertSession()->pageTextContains('Pairing codes must be between 8 and 64 characters.');

    $this->submitForm([
      'pairing[code_length]' => 16,
      'bundles[node][article]' => TRUE,
      'pairing[require_approval]' => TRUE,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $config = $this->config('content_relay_origin.settings');
    $this->assertSame(16, $config->get('pairing.code_length'));
    $this->assertSame(['node' => ['article']], $config->get('bundles'));
    $this->assertTrue($config->get('pairing.require_approval'));
  }

  /**
   * A generated code is shown once, at the configured length.
   */
  public function testPairingCode(): void {
    $this->config('content_relay_origin.settings')->set('pairing.code_length', 16)->set('bundles', [
      'node' => ['article', 'page'],
    ])->save();
    $this->drupalGet('admin/config/services/content-relay/origin/pairing-code');
    $this->submitForm(['limit' => 'selected'], 'Generate code');
    $this->assertSession()->pageTextContains('Select at least one bundle, or allow everything.');

    $this->submitForm(['limit' => 'selected', 'bundles[node.article]' => TRUE], 'Generate code');
    $code = $this->getSession()->getPage()->find('css', '.content-relay-pairing-code')->getText();
    $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/', $code);
    $this->assertSession()->pageTextContains('It works once and is not shown again.');

    $this->drupalGet('admin/config/services/content-relay/origin/pairing-code');
    $this->assertSession()->pageTextNotContains($code);
  }

  /**
   * Replicas are listed, and approval releases a pending one.
   */
  public function testReplicaListAndApproval(): void {
    $this->config('content_relay_origin.settings')->set('pairing.require_approval', TRUE)->save();
    $this->registerReplica(Replica::PENDING);
    $this->drupalGet('admin/config/services/content-relay/origin/replicas');
    $this->assertSession()->pageTextContains('China');
    $this->assertSession()->pageTextContains('Waiting for approval');
    $this->assertSession()->pageTextContains('v1');

    $this->clickLink('Approve');
    $this->assertSession()->pageTextContains('Approve replica China?');
    $this->submitForm([], 'Approve');
    $this->assertSession()->pageTextContains('Replica China updated.');
    $this->assertSession()->pageTextContains('Active');
    $this->assertSame(Replica::ACTIVE, $this->container->get('database')->select('content_relay_replica', 'r')->fields('r', ['status'])->execute()->fetchField());

    $this->clickLink('Suspend');
    $this->submitForm([], 'Suspend');
    $this->assertSession()->pageTextContains('Suspended');
    $this->clickLink('Resume');
    $this->submitForm([], 'Resume');
    $this->assertSession()->pageTextContains('Active');

    // Actions not possible from the current state do not exist.
    $this->drupalGet('admin/config/services/content-relay/origin/replicas/' . self::REPLICA . '/approve');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * The contract page shows the summary and history, and proposes changes.
   */
  public function testContractPage(): void {
    $this->registerReplica();
    $this->drupalGet('admin/config/services/content-relay/origin/replicas/' . self::REPLICA . '/contract');
    $this->assertSession()->pageTextContains('China receives Article and Basic page in all languages, immediately, unencrypted.');
    $this->assertSession()->pageTextContains('First version');

    // The replica is not reachable from the test, so the change waits.
    $this->submitForm(['limit' => 'selected', 'bundles[node.article]' => TRUE], 'Propose new contract');
    $this->assertSession()->pageTextContains('Contract version 2 was proposed. The replica has not acknowledged it yet');
    $this->assertSession()->pageTextContains('Waiting for acknowledgement');
    $this->assertSession()->pageTextContains('origin_terms.allowed_bundles');
  }

  /**
   * Each page needs its own permission.
   */
  public function testPermissions(): void {
    $this->registerReplica();
    $pages = [
      'admin/config/services/content-relay/origin' => 'administer content relay origin',
      'admin/config/services/content-relay/origin/replicas' => 'administer content relay replicas',
      'admin/config/services/content-relay/origin/replicas/' . self::REPLICA . '/contract' => 'administer content relay replicas',
      'admin/config/services/content-relay/origin/replicas/' . self::REPLICA . '/suspend' => 'administer content relay replicas',
      'admin/config/services/content-relay/origin/pairing-code' => 'generate content relay pairing codes',
      'admin/reports/content-relay' => 'view content relay status report',
      'admin/reports/content-relay/events' => 'view content relay event log',
    ];
    $all = [
      'administer content relay origin',
      'administer content relay replicas',
      'generate content relay pairing codes',
      'view content relay status report',
      'view content relay event log',
    ];
    foreach ($pages as $path => $permission) {
      $others = array_diff($all, [$permission]);
      $this->drupalLogin($this->drupalCreateUser(array_values($others)));
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
    // An item's not syncable page needs its own permission.
    $node = $this->drupalCreateNode(['type' => 'article', 'title' => 'Guarded']);
    $this->drupalLogin($this->drupalCreateUser(array_merge($all, ['push content relay items', 'access content'])));
    $this->drupalGet('node/' . $node->id() . '/content-relay/not-syncable');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['mark content relay items not syncable', 'access content']));
    $this->drupalGet('node/' . $node->id() . '/content-relay/not-syncable');
    $this->assertSession()->statusCodeEquals(200);
  }

}
