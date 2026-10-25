<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests translations on the replica: filters, mapping, local translations.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MultilingualTest extends ReplicaTestBase {

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
    foreach (['fr', 'zh-hans', 'de'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')->setLanguageAlterable(TRUE)->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * An origin article with translations, as a replica filtering to some gets.
   *
   * @param array<string, string> $titles
   *   Titles by langcode, the default first.
   * @param string[]|null $filter
   *   The languages the replica gets, or NULL for all.
   * @param string|null $uuid
   *   The UUID of an item this site may have, for a later version of it.
   */
  private function translated(array $titles, ?array $filter = NULL, ?string $uuid = NULL): array {
    $default = array_key_first($titles);
    $node = Node::create(['type' => 'article', 'title' => $titles[$default], 'langcode' => $default, 'uid' => 0]);
    foreach (array_slice($titles, 1, NULL, TRUE) as $langcode => $title) {
      $node->addTranslation($langcode, ['title' => $title]);
    }
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node, new NormalizationContext($filter))), TRUE);
    $node->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    // A later version of an item this site may already have.
    if ($uuid !== NULL) {
      $snapshot['uuid'] = $uuid;
    }
    return $snapshot;
  }

  /**
   * The sync state of an item.
   */
  private function state(string $uuid): array {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid);
  }

  /**
   * Titles of the local copy, by langcode.
   */
  private function titles(string $uuid): array {
    $node = $this->local($uuid);
    $titles = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $titles[$langcode] = $node->getTranslation($langcode)->label();
    }
    ksort($titles);
    return $titles;
  }

  /**
   * Translations arrive as one entity; a deleted one goes, only that one.
   */
  public function testTranslationsAsOneEntity(): void {
    $snapshot = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour', 'zh-hans' => '你好 👋']);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->assertSame(['en' => 'Hello', 'fr' => 'Bonjour', 'zh-hans' => '你好 👋'], $this->titles($snapshot['uuid']));
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);

    $without_fr = $this->translated(['en' => 'Hello', 'zh-hans' => '你好 👋'], NULL, $snapshot['uuid']);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($without_fr, Operation::Update, 2))->result);
    $this->assertSame(['en' => 'Hello', 'zh-hans' => '你好 👋'], $this->titles($snapshot['uuid']));
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);
  }

  /**
   * Without the origin's default language, the copy keeps its own.
   */
  public function testDefaultLanguageFilteredOut(): void {
    $snapshot = $this->translated(['en' => 'Hello', 'zh-hans' => '你好'], ['zh-hans']);
    $this->assertSame(['zh-hans'], array_keys($snapshot['translations']));
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $node = $this->local($snapshot['uuid']);
    $this->assertSame('zh-hans', $node->getUntranslated()->language()->getId());
    $this->assertSame(['zh-hans' => '你好'], $this->titles($snapshot['uuid']));
    $state = $this->state($snapshot['uuid']);
    // Hashed in the origin's terms, so it is in sync, and flagged.
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
    $this->assertSame('en', $state['source_default_langcode']);
    $this->assertSame('zh-hans', $state['notes']['default_language_kept']);
  }

  /**
   * Origin langcodes can be kept under another code here.
   */
  public function testLanguageMapping(): void {
    ConfigurableLanguage::createFromLangcode('zh-cn')->save();
    $this->config('content_relay_replica.settings')->set('languages.map', [['origin' => 'zh-hans', 'local' => 'zh-cn']])->save();
    $snapshot = $this->translated(['en' => 'Hello', 'zh-hans' => '你好']);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->assertSame(['en' => 'Hello', 'zh-cn' => '你好'], $this->titles($snapshot['uuid']));
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
    $this->assertSame(['en', 'zh-hans'], array_keys($state['source_translation_hashes']));
  }

  /**
   * A language this site does not have is skipped and flagged, or rejected.
   */
  public function testUnknownLanguage(): void {
    ConfigurableLanguage::load('fr')->delete();
    $snapshot = $this->translated(['en' => 'Hello', 'de' => 'Hallo']);
    $snapshot['translations']['fr'] = $snapshot['translations']['de'];
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame(['de' => 'Hallo', 'en' => 'Hello'], $this->titles($snapshot['uuid']));
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
    $this->assertSame(['fr'], $state['notes']['skipped_languages']);

    $this->config('content_relay_replica.settings')->set('languages.unknown', 'reject')->save();
    $other = $this->translated(['en' => 'Other']);
    $other['translations']['fr'] = $other['translations']['en'];
    $result = $this->apply($this->event($other, Operation::Create, 2));
    $this->assertSame('language_missing', $result->code);
    $this->assertNull($this->local($other['uuid']));
  }

  /**
   * Translations made here are left alone and do not count.
   */
  public function testLocalTranslationsAreKept(): void {
    $snapshot = $this->translated(['en' => 'Hello']);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->local($snapshot['uuid'])->addTranslation('de', ['title' => 'Hallo hier'])->save();

    $updated = $this->translated(['en' => 'Hello again'], NULL, $snapshot['uuid']);
    $result = $this->apply($this->event($updated, Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertNull($result->code, 'A local translation is not a local edit.');
    $this->assertSame(['de' => 'Hallo hier', 'en' => 'Hello again'], $this->titles($snapshot['uuid']));
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
    $this->assertSame(['de'], $state['local_translations']);
  }

  /**
   * The origin adding a language this site wrote itself is a conflict.
   */
  public function testOriginAddsLanguageMadeHere(): void {
    $this->useFakeOrigin();
    $this->config('content_relay_replica.settings')->set('conflicts.on_event', 'reject')->save();
    $snapshot = $this->translated(['en' => 'Hello']);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->local($snapshot['uuid'])->addTranslation('fr', ['title' => 'Bonjour d’ici'])->save();

    $with_fr = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour'], NULL, $snapshot['uuid']);
    $result = $this->apply($this->event($with_fr, Operation::Update, 2));
    $this->assertSame(ApplyResult::CONFLICT, $result->result);
    $this->assertSame('Bonjour d’ici', $this->titles($snapshot['uuid'])['fr']);

    // The diff shows the two French versions side by side.
    $this->serveItem($with_fr, 2);
    $diff = $this->container->get('content_relay_replica.conflicts')->diff('node', $snapshot['uuid']);
    $this->assertContains(['field' => 'Title', 'langcode' => 'fr', 'local' => 'Bonjour d’ici', 'origin' => 'Bonjour'], $diff['rows']);
    $this->assertSame([], array_values(array_filter($diff['rows'], fn (array $row) => $row['langcode'] === 'en')));

    // Keep ours: only French is detached; the rest follows the origin.
    $this->serveItem($this->translated(['en' => 'Hello, updated', 'fr' => 'Bonjour'], NULL, $snapshot['uuid']), 3);
    $this->container->get('content_relay_replica.conflicts')->keepLocal('node', $snapshot['uuid'], 'fr');
    $this->assertSame(['en' => 'Hello, updated', 'fr' => 'Bonjour d’ici'], $this->titles($snapshot['uuid']));
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(['fr'], $state['detached_translations']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
  }

  /**
   * A detached translation stops following the origin; the rest does not.
   */
  public function testTranslationDetach(): void {
    $this->useFakeOrigin();
    $snapshot = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour']);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertTrue($this->container->get('content_relay_replica.detacher')->detachTranslation('node', $snapshot['uuid'], 'fr'));
    $this->local($snapshot['uuid'])->getTranslation('fr')->setTitle('Bonjour à nous')->save();

    $both = $this->translated(['en' => 'Hello 2', 'fr' => 'Bonjour 2'], NULL, $snapshot['uuid']);
    $result = $this->apply($this->event($both, Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertNull($result->code, 'Edits to a detached translation are not a conflict.');
    $this->assertSame(['en' => 'Hello 2', 'fr' => 'Bonjour à nous'], $this->titles($snapshot['uuid']));
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);

    // Reattached, taking the origin's version.
    $this->assertTrue($this->container->get('content_relay_replica.detacher')->reattachTranslation('node', $snapshot['uuid'], 'fr'));
    $this->apply($this->event($this->translated(['en' => 'Hello 2', 'fr' => 'Bonjour 2'], NULL, $snapshot['uuid']), Operation::Update, 3));
    $this->assertSame(['en' => 'Hello 2', 'fr' => 'Bonjour 2'], $this->titles($snapshot['uuid']));
    $this->assertSame([], $this->state($snapshot['uuid'])['detached_translations']);
  }

  /**
   * Translations the origin withholds stay as they are here.
   */
  public function testWithheldTranslationsStay(): void {
    $snapshot = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour']);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $only_en = $this->translated(['en' => 'Hello 2'], NULL, $snapshot['uuid']);
    $result = $this->apply($this->event($only_en, Operation::Update, 2, ['withheld_langcodes' => ['fr']]));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame(['en' => 'Hello 2', 'fr' => 'Bonjour'], $this->titles($snapshot['uuid']));
    $this->assertSame(['fr'], $this->state($snapshot['uuid'])['withheld_translations']);
  }

  /**
   * A new mapping moves translations already here, keeping them in sync.
   */
  public function testMoveForNewMapping(): void {
    $snapshot = $this->translated(['en' => 'Hello', 'zh-hans' => '你好']);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    ConfigurableLanguage::createFromLangcode('zh-cn')->save();
    // The node loaded earlier knows only the languages there were then.
    $this->container->get('language_manager')->reset();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->config('content_relay_replica.settings')->set('languages.map', [['origin' => 'zh-hans', 'local' => 'zh-cn']])->save();
    $this->container->get('content_relay_replica.language_subscription')->move('zh-hans', 'zh-cn');
    $queue = $this->container->get('queue')->get(LanguageSubscription::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(LanguageSubscription::QUEUE);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
    $this->assertSame(['en' => 'Hello', 'zh-cn' => '你好'], $this->titles($snapshot['uuid']));
    $this->assertNull($this->container->get('content_relay_replica.local_changes')->editedTranslations($this->local($snapshot['uuid']), $this->state($snapshot['uuid'])));
  }

  /**
   * A failed save leaves every translation as it was.
   */
  public function testFailedSaveKeepsTranslations(): void {
    $snapshot = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour']);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $node = $this->local($snapshot['uuid']);
    $revisions = count($this->revisions($node));

    $exploding = $this->translated(['en' => 'Hello', 'fr' => 'Bonjour 2'], NULL, $snapshot['uuid']);
    $exploding['translations']['en']['title'][0]['value'] = 'Explode';
    $result = $this->apply($this->event($exploding, Operation::Update, 2));
    $this->assertSame([422, 'save_failed'], [$result->httpStatus, $result->code]);
    $this->assertSame(['en' => 'Hello', 'fr' => 'Bonjour'], $this->titles($snapshot['uuid']));
    $this->assertCount($revisions, $this->revisions($this->local($snapshot['uuid'])));
    $this->assertSame(1, (int) $this->state($snapshot['uuid'])['source_revision']);
  }

}
