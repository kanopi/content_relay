<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what the origin sends a replica that receives some languages.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MultilingualTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    foreach (['fr', 'zh-hans'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')->setLanguageAlterable(TRUE)->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    // The fake replica receives Chinese and English only.
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $requested = ['bundles' => ['node' => ['article']]] + SubscriptionContract::defaultReplicaTerms();
    $requested['languages'] = [
      'mode' => SubscriptionContract::LANGUAGES_SELECTED,
      'selected' => ['en', 'zh-hans'],
      'per_bundle' => [],
    ];
    $this->assertSame(200, $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code, ['node' => ['article']], ['requested' => $requested]))->getStatusCode());
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]))->getStatusCode());
  }

  /**
   * An article with translations.
   *
   * @param array<string, string> $titles
   *   Titles by langcode, the default first.
   */
  private function translated(array $titles): NodeInterface {
    $default = array_key_first($titles);
    $node = Node::create(['type' => 'article', 'title' => $titles[$default], 'langcode' => $default, 'uid' => 0]);
    foreach (array_slice($titles, 1, NULL, TRUE) as $langcode => $title) {
      $node->addTranslation($langcode, ['title' => $title]);
    }
    $node->save();
    return $node;
  }

  /**
   * The report rows of an item for the fake replica, by langcode.
   */
  private function rows(NodeInterface $node): array {
    $rows = $this->container->get('database')->select(ReportIndex::TABLE, 'r')
      ->fields('r')
      ->condition('uuid', $node->uuid())
      ->condition('replica_id', $this->replica->siteId)
      ->execute()
      ->fetchAllAssoc('langcode');
    return array_map(fn (object $row) => (array) $row, $rows);
  }

  /**
   * Languages the replica does not get are not in what it is sent at all.
   */
  public function testFilteredPayload(): void {
    $node = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour', 'zh-hans' => '你好']);
    $this->deliver();
    $event = end($this->replica->events);
    $this->assertSame(['en', 'zh-hans'], array_keys($event['snapshot']['translations']));
    $this->assertSame(['en', 'zh-hans'], array_keys($event['hash']['translations']));
    $this->assertStringNotContainsString('Bonjour', json_encode($this->replica->envelopes));
    // In sync, French aside.
    $this->assertSame(['en', 'zh-hans'], array_keys($this->rows($node)));
    $this->assertSame([ReportIndex::IN_SYNC], array_values(array_unique(array_column($this->rows($node), 'status'))));
  }

  /**
   * The default language filtered out: the item is sent without it.
   */
  public function testDefaultLanguageFilteredOut(): void {
    $this->translated(['fr' => 'Bonjour', 'zh-hans' => '你好']);
    $this->deliver();
    $event = end($this->replica->events);
    $this->assertSame('fr', $event['default_langcode']);
    $this->assertSame(['zh-hans'], array_keys($event['snapshot']['translations']));
  }

  /**
   * An item in none of the replica's languages is not sent.
   */
  public function testNoLanguages(): void {
    $node = $this->translated(['fr' => 'Seulement en français']);
    $this->deliver();
    $this->assertSame([], $this->replica->events);
    $row = $this->container->get('database')->select(Outbox::TABLE, 'o')->fields('o', ['status', 'result_code'])->execute()->fetchAssoc();
    $this->assertSame(['status' => EventStatus::SKIPPED, 'result_code' => ReportIndex::NO_LANGUAGES], $row);
    $this->assertSame(ReportIndex::NO_LANGUAGES, $this->rows($node)['fr']['status']);
    $this->assertSame('0', $this->rows($node)['fr']['item_out_of_sync']);
  }

  /**
   * Language-neutral items pass any language filter.
   */
  public function testLanguageNeutral(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Neutral', 'langcode' => 'zxx', 'uid' => 0]);
    $node->save();
    $this->deliver();
    $this->assertSame(['zxx'], array_keys(end($this->replica->events)['snapshot']['translations']));
  }

  /**
   * One translation can be kept from replicas, by either side.
   */
  public function testTranslationBlocks(): void {
    $node = $this->translated(['en' => 'Hello', 'zh-hans' => '你好']);
    $this->deliver();
    $control = $this->container->get('content_relay_origin.item_sync_control');

    $this->assertSame([$this->replica->siteId], $control->setTranslationSyncable($node, 'zh-hans', FALSE));
    $this->deliver();
    $event = end($this->replica->events);
    $this->assertSame(['en'], array_keys($event['snapshot']['translations']));
    $this->assertSame(['zh-hans'], $event['withheld_langcodes']);
    $this->assertSame(ReportIndex::NOT_SYNCABLE, $this->rows($node)['zh-hans']['status']);
    $this->assertSame(ReportIndex::IN_SYNC, $this->rows($node)['en']['status']);

    $control->setTranslationSyncable($node, 'zh-hans', TRUE);
    $this->deliver();
    $this->assertSame(['en', 'zh-hans'], array_keys(end($this->replica->events)['snapshot']['translations']));

    // The replica detaches its Chinese translation.
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', [
      'entity_type' => 'node',
      'uuid' => $node->uuid(),
      'bundle' => 'article',
      'langcode' => 'zh-hans',
      'by' => 'editor',
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(ReportIndex::DETACHED_BY_REPLICA, $this->rows($node)['zh-hans']['status']);
    $this->assertSame('editor', $this->rows($node)['zh-hans']['detached_by']);
    $node->setTitle('Hello again')->save();
    $this->deliver();
    $this->assertSame(['zh-hans'], end($this->replica->events)['withheld_langcodes']);
  }

  /**
   * Replicas are told when the origin adds or removes a language.
   */
  public function testLanguageNotices(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::load('de')->delete();
    $this->assertSame([
      [['change' => 'added', 'langcode' => 'de', 'name' => 'German']],
      [['change' => 'removed', 'langcode' => 'de', 'name' => 'German']],
    ], $this->replica->languageNotices);
  }

}
