<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\content_relay_test\FakeOrigin;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\content_relay_replica\Schema\SchemaWatcher;
use Drupal\language\Entity\ConfigurableLanguage;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pairing and changing the subscription through the replica's pages.
 *
 * The origin is the fake one in content_relay_test, inside the site under
 * test, so the forms run against real HTTP client code and signed contracts.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReplicaAdminTest extends BrowserTestBase {

  use DrushTestTrait;
  use FileKeyTrait;

  private const CODE = 'K7QM-3XRP-V9TD';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_relay_replica', 'content_relay_test', 'node', 'language'];

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
    $this->createFileKey('replica_signing');
    FakeOrigin::install($this->container->get('state'), self::CODE, ['node' => ['article']]);
    $this->drupalLogin($this->drupalCreateUser([
      'administer content relay replica',
      'change content relay subscription',
      'change content relay languages',
    ]));
  }

  /**
   * Pairs through the form.
   */
  private function pair(): void {
    $this->drupalGet('admin/config/services/content-relay/replica/pair');
    $this->submitForm([
      'origin_url' => 'https://' . FakeOrigin::HOST,
      'code' => 'k7qm 3xrp v9td',
      'bundles[node][article]' => TRUE,
      'bundles[node][page]' => TRUE,
    ], 'Pair');
  }

  /**
   * Pairing shows the contract, including what was not granted.
   */
  public function testPairing(): void {
    $this->drupalGet('admin/config/services/content-relay/replica');
    $this->assertSession()->pageTextContains('This site is not paired with an origin yet.');

    $this->pair();
    $this->assertSession()->pageTextContains('Paired. Contract version 1 is in place.');
    $this->assertSession()->addressEquals('admin/config/services/content-relay/replica');
    $this->assertSession()->pageTextContains('Fake origin');
    $this->assertSession()->pageTextContains('You receive Article in all languages, immediately, unencrypted.');
    $this->assertSession()->pageTextContains('Requested but not granted: Basic page (Not allowed for this replica.)');

    $this->drupalGet('admin/config/services/content-relay/replica/pair');
    $this->assertSession()->pageTextContains('This site is paired with Fake origin');
  }

  /**
   * Bad input is refused with a reason.
   */
  public function testPairingRefusals(): void {
    $this->drupalGet('admin/config/services/content-relay/replica/pair');
    $this->submitForm(['origin_url' => 'http://' . FakeOrigin::HOST, 'code' => self::CODE], 'Pair');
    $this->assertSession()->pageTextContains('Content Relay pairs only over HTTPS.');

    $this->submitForm(['origin_url' => 'https://' . FakeOrigin::HOST, 'code' => 'WRONG'], 'Pair');
    $this->assertSession()->pageTextContains('The origin refused to pair: The pairing code is not valid.');
    $this->drupalGet('admin/config/services/content-relay/replica');
    $this->assertSession()->pageTextContains('not paired');
  }

  /**
   * Changing languages proposes a new version, shown with its history.
   */
  public function testChangeSubscription(): void {
    $this->pair();
    $this->submitForm([
      'terms[language_mode]' => 'selected',
      'terms[languages][en]' => TRUE,
      'terms[paused]' => TRUE,
    ], 'Propose changes');
    $this->assertSession()->pageTextContains('The origin confirmed contract version 2.');
    $this->assertSession()->pageTextContains('You receive Article in English, immediately, unencrypted. Delivery is paused.');
    $this->assertSession()->pageTextContains('replica_terms.languages.mode: all → selected');
    $this->assertSame([1, 2], $this->container->get('state')->get(FakeOrigin::STATE)['acks']);
  }

  /**
   * An unreachable origin leaves the change pending and the contract as is.
   */
  public function testOriginUnreachable(): void {
    $this->pair();
    $state = $this->container->get('state');
    $state->set(FakeOrigin::STATE, ['down' => TRUE] + $state->get(FakeOrigin::STATE));
    $this->submitForm(['terms[paused]' => TRUE], 'Propose changes');
    $this->assertSession()->pageTextContains('The origin could not be reached. The change will be retried');
    $this->assertSession()->pageTextContains('A change is waiting to reach the origin');
    $this->assertSession()->pageTextNotContains('Delivery is paused.');
  }

  /**
   * Each page needs its permission.
   */
  public function testPermissions(): void {
    $this->pair();
    $this->drupalLogin($this->drupalCreateUser(['change content relay subscription']));
    $this->drupalGet('admin/config/services/content-relay/replica/pair');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer content relay replica']));
    $this->drupalGet('admin/config/services/content-relay/replica');
    $this->assertSession()->statusCodeEquals(403);
    // Languages need a permission of their own.
    $this->drupalLogin($this->drupalCreateUser(['change content relay subscription']));
    $this->drupalGet('admin/config/services/content-relay/replica');
    $this->assertSession()->fieldExists('terms[paused]');
    $this->assertSession()->fieldNotExists('terms[language_mode]');
  }

  /**
   * The settings form saves every setting and checks what it is given.
   */
  public function testSettingsForm(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->pair();
    $this->drupalGet('admin/config/services/content-relay/replica/settings');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm(['map' => 'zh-hans|xx', 'origin_allowed_ips' => 'nonsense'], 'Save configuration');
    $this->assertSession()->pageTextContains('Language xx does not exist on this site.');
    $this->assertSession()->pageTextContains('"nonsense" is not an IP address or range.');

    $this->submitForm([
      'on_event' => 'reject',
      'local_only' => 'node.article: field_notes, field_extra',
      'map' => 'fr-ca|fr',
      'move' => TRUE,
      'unknown' => 'reject',
      'interval' => 3600,
      'max_items' => 25,
      'origin_allowed_ips' => "203.0.113.5\n2001:db8::/32",
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSession()->pageTextContains('Translations in fr-ca move to fr');
    $config = $this->config('content_relay_replica.settings');
    $this->assertSame('reject', $config->get('conflicts.on_event'));
    $this->assertSame([['entity_type' => 'node', 'bundle' => 'article', 'fields' => ['field_notes', 'field_extra']]], $config->get('local_only'));
    $this->assertSame([['origin' => 'fr-ca', 'local' => 'fr']], $config->get('languages.map'));
    $this->assertSame(25, $config->get('reconcile.max_items'));
    $this->assertSame(['203.0.113.5', '2001:db8::/32'], $config->get('origin_allowed_ips'));
    $this->assertGreaterThan(0, $this->container->get('queue')->get(LanguageSubscription::QUEUE)->numberOfItems());

    // Shown back as typed.
    $this->drupalGet('admin/config/services/content-relay/replica/settings');
    $this->assertSession()->fieldValueEquals('local_only', 'node.article: field_notes, field_extra');
    $this->assertSession()->fieldValueEquals('map', 'fr-ca|fr');
  }

  /**
   * A bundle can receive other languages than the rest of the site.
   */
  public function testLanguagesPerBundle(): void {
    $this->pair();
    $this->submitForm([
      'terms[per_bundle][node.article][mode]' => 'selected',
      'terms[per_bundle][node.article][selected][en]' => TRUE,
    ], 'Propose changes');
    $this->assertSession()->pageTextContains('The origin confirmed contract version 2.');
    $terms = $this->container->get('content_relay_replica.subscription')->getReplicaTerms();
    $this->assertSame(['node.article' => ['mode' => 'selected', 'selected' => ['en']]], $terms['languages']['per_bundle']);
  }

  /**
   * Switching to selected languages with none ticked keeps what is received.
   */
  public function testSwitchToSelectedLanguages(): void {
    $this->container->get('state')->set(SchemaWatcher::STATE, ['_languages' => ['en' => 'English', 'es' => 'Spanish']]);
    $this->pair();
    $this->submitForm(['terms[language_mode]' => 'selected'], 'Propose changes');
    $this->assertSession()->pageTextContains('The origin confirmed contract version 2.');
    $terms = $this->container->get('content_relay_replica.subscription')->getReplicaTerms();
    $this->assertSame(['selected', ['en', 'es']], [$terms['languages']['mode'], $terms['languages']['selected']]);
    $this->assertSession()->checkboxChecked('terms[languages][en]');
    $this->assertSession()->checkboxChecked('terms[languages][es]');
  }

  /**
   * Pairing and status from Drush, with exit codes.
   */
  public function testDrush(): void {
    $this->drush('content-relay:status', [], [], NULL, NULL, 1);
    $this->drush('content-relay:pair', ['http://' . FakeOrigin::HOST, self::CODE], ['bundles' => 'node:article'], NULL, NULL, 1);
    $this->drush('content-relay:pair', ['https://' . FakeOrigin::HOST, self::CODE], ['bundles' => 'node:article']);
    $this->assertStringContainsString('Paired', $this->getErrorOutput());
    $this->drush('content-relay:status');
    $this->assertStringContainsString('Contract: 1', $this->getOutput());
    $this->drush('content-relay:languages');
  }

}
