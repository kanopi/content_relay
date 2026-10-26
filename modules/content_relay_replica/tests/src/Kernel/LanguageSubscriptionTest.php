<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests changes to the languages a replica receives.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class LanguageSubscriptionTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * The service under test.
   */
  private LanguageSubscription $languages;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    foreach (['fr', 'de'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')->setLanguageAlterable(TRUE)->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->languages = $this->container->get('content_relay_replica.language_subscription');
  }

  /**
   * A contract receiving articles in all languages, or selected ones.
   *
   * @param int $version
   *   The version.
   * @param string[]|null $selected
   *   The languages, or NULL for all.
   */
  private function languageContract(int $version, ?array $selected): SubscriptionContract {
    $languages = $selected === NULL
      ? ['mode' => SubscriptionContract::LANGUAGES_ALL, 'selected' => [], 'per_bundle' => []]
      : ['mode' => SubscriptionContract::LANGUAGES_SELECTED, 'selected' => $selected, 'per_bundle' => []];
    $terms = $this->contract($version, ['node' => ['article']])->toArray();
    $terms['replica_terms']['languages'] = $languages;
    $terms['effective']['languages'] = $languages;
    return new SubscriptionContract($terms);
  }

  /**
   * Applies an English and French article.
   */
  private function synced(): array {
    $node = Node::create(['type' => 'article', 'title' => 'Hello', 'langcode' => 'en', 'uid' => 0]);
    $node->addTranslation('fr', ['title' => 'Bonjour']);
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node, new NormalizationContext())), TRUE);
    $node->delete();
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    return $snapshot;
  }

  /**
   * Works through the language queue.
   */
  private function runQueue(): void {
    $queue = $this->container->get('queue')->get(LanguageSubscription::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(LanguageSubscription::QUEUE);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * The state of an item.
   */
  private function state(string $uuid): array {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid);
  }

  /**
   * In selected mode, a language the origin adds waits to be added or ignored.
   */
  public function testAddedLanguageIsPending(): void {
    $this->container->get('content_relay_replica.contracts')->save($this->languageContract(2, ['en']));
    $this->languages->receiveNotice([['change' => 'added', 'langcode' => 'de', 'name' => 'German']]);
    $this->assertSame(['de' => 'German'], $this->languages->pending());

    $terms = $this->languages->decide(['languages' => ['mode' => 'selected', 'selected' => ['en']]], 'de', TRUE);
    $this->assertSame(['de', 'en'], $terms['languages']['selected']);
    $this->assertSame([], $this->languages->pending());

    // Ignored, it is not offered again.
    $this->languages->receiveNotice([['change' => 'added', 'langcode' => 'it', 'name' => 'Italian']]);
    $terms = $this->languages->decide([], 'it', FALSE);
    $this->assertSame(['it'], $terms['ignored_languages']);
    $terms['languages'] = ['mode' => 'selected', 'selected' => ['en'], 'per_bundle' => []];
    $contract = $this->languageContract(3, ['en'])->toArray();
    $contract['replica_terms']['ignored_languages'] = ['it'];
    $this->container->get('content_relay_replica.contracts')->save(new SubscriptionContract($contract));
    $this->languages->receiveNotice([['change' => 'added', 'langcode' => 'it', 'name' => 'Italian']]);
    $this->assertSame([], $this->languages->pending());
  }

  /**
   * In all-languages mode, nothing waits.
   */
  public function testAllLanguagesNeedNoDecision(): void {
    $this->languages->receiveNotice([['change' => 'added', 'langcode' => 'de', 'name' => 'German']]);
    $this->assertSame([], $this->languages->pending());
  }

  /**
   * Dropping a language keeps its translations as local ones, by default.
   */
  public function testDroppedLanguageIsKept(): void {
    $snapshot = $this->synced();
    $previous = $this->container->get('content_relay_replica.contracts')->getCurrent();
    $this->languages->contractChanged($previous, $this->languageContract(2, ['en']));
    $this->runQueue();

    $state = $this->state($snapshot['uuid']);
    $this->assertSame(['en'], array_keys($state['source_translation_hashes']));
    $this->assertSame(['fr'], $state['local_translations']);
    $this->assertSame('Bonjour', $this->local($snapshot['uuid'])->getTranslation('fr')->label());
    // Still in sync, by what it still receives.
    $result = $this->container->get('content_relay_replica.local_changes')->editedTranslations($this->local($snapshot['uuid']), $state);
    $this->assertNull($result);
  }

  /**
   * Or deletes them, only them.
   */
  public function testDroppedLanguageIsDeleted(): void {
    $snapshot = $this->synced();
    $previous = $this->container->get('content_relay_replica.contracts')->getCurrent();
    $this->languages->setRemovalMode('delete');
    $this->languages->contractChanged($previous, $this->languageContract(2, ['en']));
    $this->runQueue();

    $node = $this->local($snapshot['uuid']);
    $this->assertNotNull($node);
    $this->assertFalse($node->hasTranslation('fr'));
    $this->assertSame(['en'], array_keys($this->state($snapshot['uuid'])['source_translation_hashes']));
  }

  /**
   * A language the origin stops sending is dropped as one this site drops.
   *
   * Kept as a local translation (or deleted, as chosen), never managed by
   * sync while excluded, and pulled again once the origin sends it again.
   */
  public function testOriginExcludesLanguage(): void {
    $snapshot = $this->synced();
    $previous = $this->container->get('content_relay_replica.contracts')->getCurrent();
    $excluded = $this->languageContract(2, NULL)->toArray();
    $excluded['effective']['languages']['excluded'] = ['fr'];
    $excluded = new SubscriptionContract($excluded);
    $this->container->get('content_relay_replica.contracts')->save($excluded);
    $this->languages->contractChanged($previous, $excluded);
    $this->runQueue();
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(['en'], array_keys($state['source_translation_hashes']));
    $this->assertSame(['fr'], $state['local_translations']);

    // An update without French leaves the local French alone.
    $update = $snapshot;
    unset($update['translations']['fr']);
    $update['translations']['en']['title'][0]['value'] = 'Hello again';
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($update, Operation::Update, 2, ['contract_version' => 2]))->result);
    $this->assertSame('Bonjour', $this->local($snapshot['uuid'])->getTranslation('fr')->label());
    $this->assertSame('Hello again', $this->local($snapshot['uuid'])->label());

    // Sent again: a backfill brings it back.
    $this->container->get('state')->delete(LanguageSubscription::BACKFILL);
    $this->languages->contractChanged($excluded, $this->languageContract(3, NULL));
    $this->assertTrue($this->container->get('state')->get(LanguageSubscription::BACKFILL));
  }

  /**
   * The origin removing a language keeps its translations here.
   */
  public function testOriginRemovesLanguage(): void {
    $snapshot = $this->synced();
    $this->languages->receiveNotice([['change' => 'removed', 'langcode' => 'fr', 'name' => 'French']]);
    $this->runQueue();
    $this->assertSame(['fr'], $this->state($snapshot['uuid'])['local_translations']);
    $this->assertTrue($this->local($snapshot['uuid'])->hasTranslation('fr'));
  }

  /**
   * Receiving more languages calls for a backfill, marked in the report.
   */
  public function testAddedLanguageBackfills(): void {
    $this->useFakeOrigin();
    $previous = $this->languageContract(2, ['en']);
    $snapshot = $this->synced();
    $this->languages->contractChanged($previous, $this->languageContract(3, NULL));
    $this->assertTrue($this->container->get('state')->get(LanguageSubscription::BACKFILL));

    // The backfill run does not wait for the interval, and what it queues is
    // shown as pending backfill.
    $this->config('content_relay_replica.settings')->set('reconcile.max_items', 1)->save();
    $other = $snapshot;
    $other['uuid'] = $this->container->get('uuid')->generate();
    $other['translations'] = ['en' => $snapshot['translations']['en']];
    $this->apply($this->event($other, Operation::Create, 1));
    $hasher = $this->container->get('content_relay.revision_hasher');
    $changed = $snapshot;
    $changed['translations']['fr']['title'][0]['value'] = 'Bonjour encore';
    $this->serveItem($changed, 2);
    $this->updateFakeOrigin(function (array &$config) use ($hasher, $changed, $other): void {
      $config['hashes'] = [
        'node:article' => [
          [
            'uuid' => $changed['uuid'],
            'revision' => 2,
            'changed' => NULL,
            'hash' => $hasher->hash($changed)->toArray(),
            'not_syncable' => FALSE,
          ],
          [
            'uuid' => $other['uuid'],
            'revision' => 2,
            'changed' => NULL,
            'hash' => $hasher->hash($changed)->toArray(),
            'not_syncable' => FALSE,
          ],
        ],
        'taxonomy_term:tags' => [],
      ];
    });
    // The origin serves both; which one the capped run takes first depends on
    // the UUIDs.
    $served = $changed;
    $served['uuid'] = $other['uuid'];
    $this->serveItem($served, 2);
    $this->container->get('state')->set('content_relay_replica.reconciled', time());
    $this->container->get('content_relay_replica.reconciler')->cron(5);
    $this->assertNull($this->container->get('state')->get(LanguageSubscription::BACKFILL));
    $statuses = [
      $this->state($changed['uuid'])['status'],
      $this->state($other['uuid'])['status'],
    ];
    $this->assertContains(SyncStatus::PENDING_BACKFILL, $statuses);

    // Once the queue is worked through, both are in sync, French included.
    $this->config('content_relay_replica.settings')->set('reconcile.max_items', 100)->save();
    $this->drainQueue(Reconciler::QUEUE);
    foreach ([$changed['uuid'], $other['uuid']] as $uuid) {
      $state = $this->state($uuid);
      $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
      $this->assertSame('Bonjour encore', $this->local($uuid)->getTranslation('fr')->label());
    }
  }

}
