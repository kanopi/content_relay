<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\ConfigTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests mapped imports: origin articles onto this site's news items.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MappingTest extends ReplicaTestBase {

  use ConfigTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation'];

  /**
   * A term that exists on both sites, with the same UUID.
   */
  private Term $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The origin's article has a body; this site has news items instead.
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body'])->save();
    NodeType::create(['type' => 'news_item', 'name' => 'News item', 'new_revision' => TRUE])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_content', 'type' => 'text_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'news_item', 'field_name' => 'field_content'])->save();
    $this->createEntityReferenceField('node', 'news_item', 'field_topics', 'Topics', 'taxonomy_term', 'default', [], -1);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_subtitle', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'news_item', 'field_name' => 'field_subtitle'])->save();
    $this->installConfig(['filter']);
    $this->term = Term::create(['vid' => 'tags', 'name' => 'Science']);
    $this->term->save();
  }

  /**
   * The article-to-news-item mapping.
   */
  private function mapping(array $overrides = []): ContentRelayMapping {
    $mapping = ContentRelayMapping::create($overrides + [
      'id' => 'node__article',
      'label' => 'Articles as news',
      'source_entity_type' => 'node',
      'source_bundle' => 'article',
      'target_entity_type' => 'node',
      'target_bundle' => 'news_item',
      'fields' => [
        ['source' => 'title', 'target' => 'title', 'process' => []],
        ['source' => 'body.value', 'target' => 'field_content.value', 'process' => []],
        [
          'source' => 'body.format',
          'target' => 'field_content.format',
          'process' => [['plugin' => 'text_format', 'configuration' => ['map' => ['full_html' => 'plain_text']]]],
        ],
        [
          'source' => 'field_tags',
          'target' => 'field_topics',
          'process' => [['plugin' => 'taxonomy_lookup', 'configuration' => ['vocabulary' => 'tags']]],
        ],
        [
          'source' => '',
          'target' => 'field_subtitle',
          'process' => [['plugin' => 'default_value', 'configuration' => ['value' => 'No subtitle']]],
        ],
        ['source' => 'uid', 'target' => 'uid', 'process' => []],
        ['source' => 'status', 'target' => 'status', 'process' => []],
      ],
      'ignored_source_fields' => ['created', 'promote', 'sticky', 'path', 'revision_uid', 'revision_log'],
    ]);
    $mapping->save();
    return $mapping;
  }

  /**
   * An origin article with a body and the shared term.
   */
  private function article(string $title, array $values = []): array {
    return $this->originArticle($title, $values + [
      'body' => ['value' => '<p>Findings</p>', 'format' => 'full_html'],
      'field_tags' => [$this->term],
    ]);
  }

  /**
   * The item with a UUID here.
   */
  private function newsItem(string $uuid): ?NodeInterface {
    return $this->local($uuid);
  }

  /**
   * Origin articles land as news items, field by field.
   */
  public function testMappedImport(): void {
    $this->mapping();
    $snapshot = $this->article('Discovery');
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(ApplyResult::APPLIED, $result->result, (string) $result->message);

    $node = $this->newsItem($snapshot['uuid']);
    $this->assertSame('news_item', $node->bundle());
    $this->assertSame('Discovery', $node->label());
    $this->assertSame('<p>Findings</p>', $node->get('field_content')->value);
    $this->assertSame('plain_text', $node->get('field_content')->format);
    $this->assertSame((string) $this->term->id(), (string) $node->get('field_topics')->target_id);
    $this->assertSame('No subtitle', $node->get('field_subtitle')->value);

    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame('mapped', $state['import_mode']);
    $this->assertSame('node__article', $state['mapping_id']);
    $this->assertSame(SyncStatus::SYNCED, $state['status']);
    // The local hash is this structure's own, kept for spotting local edits.
    $this->assertSame($state['local_hash'], $state['expected_hash']);
    $this->assertNotSame($state['source_hash'], $state['local_hash']);

    // Updates go to the same item.
    $updated = $this->retitle($snapshot, 'Discovery, revised');
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($updated, Operation::Update, 2))->result);
    $this->assertSame('Discovery, revised', $this->newsItem($snapshot['uuid'])->label());
    $this->assertCount(2, $this->revisions($this->newsItem($snapshot['uuid'])));

    // And deletes.
    $this->assertSame(ApplyResult::DELETED, $this->apply($this->event(NULL, Operation::Delete, 3, ['entity_uuid' => $snapshot['uuid']]))->result);
    $this->assertNull($this->newsItem($snapshot['uuid']));
  }

  /**
   * A required field here without a mapping or default refuses the item.
   */
  public function testUnmappedRequiredField(): void {
    $mapping = $this->mapping();
    $fields = array_values(array_filter($mapping->getFieldMappings(), fn ($f) => $f['target'] !== 'title'));
    $mapping->set('fields', $fields)->save();
    $this->assertContains('Required field "Title" here is not mapped and has no default value.', $this->container->get('content_relay_replica.mapped_importer')->validate($mapping));

    $snapshot = $this->article('No title here');
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(422, $result->httpStatus);
    $this->assertSame('mapping_failed', $result->code);
    $this->assertStringContainsString('Required field "Title"', (string) $result->message);
    $this->assertNull($this->newsItem($snapshot['uuid']));

    // With "allow", it is left to validation, which refuses a missing title.
    $mapping->set('unmapped_required', 'allow')->save();
    $result = $this->apply($this->event($snapshot, Operation::Create, 2));
    $this->assertSame('validation_failed', $result->code);
  }

  /**
   * Origin fields the mapping does not know: flagged, the rest applied.
   */
  public function testSchemaDrift(): void {
    $mapping = $this->mapping(['ignored_source_fields' => []]);
    $snapshot = $this->article('Drifting');
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::SCHEMA_DRIFT, $state['status']);
    $this->assertStringContainsString('created', (string) $state['last_error']);
    $this->assertSame('Drifting', $this->newsItem($snapshot['uuid'])->label());

    // Acknowledged by ignoring them: synced on the next update.
    $mapping->set('ignored_source_fields', ['created', 'promote', 'sticky', 'path', 'revision_uid', 'revision_log'])->save();
    $this->apply($this->event($this->retitle($snapshot, 'Settled'), Operation::Update, 2));
    $this->assertSame(SyncStatus::SYNCED, $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['status']);
  }

  /**
   * Mappings are configuration, exported with their dependencies.
   */
  public function testMappingIsConfiguration(): void {
    $this->mapping();
    $exported = $this->container->get('config.storage')->read('content_relay_replica.mapping.node__article');
    $this->assertSame('news_item', $exported['target_bundle']);
    $this->assertContains('node.type.news_item', $exported['dependencies']['config']);
    $this->assertSame('taxonomy_lookup', $exported['fields'][3]['process'][0]['plugin']);
  }

  /**
   * Without a mapping, or with it disabled, content is taken as it is.
   */
  public function testDisabledMappingIsIgnored(): void {
    $this->mapping(['status' => FALSE]);
    // As it is, the format must exist here too.
    $snapshot = $this->article('As it is', ['body' => ['value' => 'Plain', 'format' => 'plain_text']]);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->assertSame('article', $this->local($snapshot['uuid'])->bundle());
  }

  /**
   * A local edit to a mapped item is a conflict; no edit, no false flag.
   */
  public function testLocalEditOnMappedItem(): void {
    $this->mapping();
    $snapshot = $this->article('Discovery');
    $this->apply($this->event($snapshot, Operation::Create, 1));

    $result = $this->apply($this->event($this->retitle($snapshot, 'Discovery 2'), Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertNull($result->code, 'An unedited mapped item is not a conflict.');
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status']);

    // Edited here, in a field the origin also fills.
    $this->newsItem($snapshot['uuid'])->set('field_subtitle', 'Our subtitle')->save();
    $result = $this->apply($this->event($this->retitle($snapshot, 'Discovery 3'), Operation::Update, 3));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame('conflict_overwritten', $result->code);
    $node = $this->newsItem($snapshot['uuid']);
    $this->assertSame(['Discovery 3', 'No subtitle'], [$node->label(), $node->get('field_subtitle')->value]);
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::CONFLICT, $state['status']);
    $edit = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($state['conflict_revision']);
    assert($edit instanceof NodeInterface);
    $this->assertSame('Our subtitle', $edit->get('field_subtitle')->value);
  }

  /**
   * A mapping made on another environment arrives through config import.
   */
  public function testMappingImportsThroughConfigSync(): void {
    $this->installConfig(['system']);
    $this->mapping();
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    ContentRelayMapping::load('node__article')->delete();
    $this->assertSame([], $this->container->get('config.storage')->listAll('content_relay_replica.mapping.'));

    $this->configImporter()->import();
    $mapping = ContentRelayMapping::load('node__article');
    assert($mapping instanceof ContentRelayMapping);
    $this->assertSame('news_item', $mapping->getTargetBundle());
    $this->assertSame([], $this->container->get('content_relay_replica.mapped_importer')->validate($mapping));
    $snapshot = $this->article('Imported mapping');
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->assertSame('news_item', $this->newsItem($snapshot['uuid'])->bundle());
  }

  /**
   * An untranslatable target takes the origin language the mapping names.
   */
  public function testUntranslatableTarget(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    foreach (['article', 'news_item'] as $bundle) {
      ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle)
        ->setLanguageAlterable(TRUE)
        ->setThirdPartySetting('content_translation', 'enabled', TRUE)
        ->save();
      $this->container->get('content_translation.manager')->setEnabled('node', $bundle, TRUE);
    }
    // The origin translates its body; this site keeps one content field.
    FieldConfig::loadByName('node', 'news_item', 'field_content')->set('translatable', FALSE)->save();
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $mapping = $this->mapping();
    $mapping->set('ignored_source_fields', [
      ...$mapping->get('ignored_source_fields'),
      'content_translation_source',
      'content_translation_outdated',
    ])->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Discovery',
      'langcode' => 'en',
      'uid' => 0,
      'body' => ['value' => '<p>English</p>', 'format' => 'full_html'],
      'field_tags' => [$this->term],
    ]);
    $node->addTranslation('fr', [
      'title' => 'Découverte',
      'body' => ['value' => '<p>Français</p>', 'format' => 'full_html'],
    ]);
    $node->save();
    $snapshot = $this->snapshot($node);
    $this->forget($node);

    // By default, from the origin's default language.
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(ApplyResult::APPLIED, $result->result, (string) $result->message);
    $item = $this->newsItem($snapshot['uuid']);
    $this->assertSame(['en', 'fr'], array_keys($item->getTranslationLanguages()));
    $this->assertSame('Découverte', $item->getTranslation('fr')->label());
    foreach (['en', 'fr'] as $langcode) {
      $this->assertSame('<p>English</p>', $item->getTranslation($langcode)->get('field_content')->value);
    }

    // Named: French feeds it, in every translation.
    $mapping->set('translation', ['untranslatable_source' => 'fr'])->save();
    $snapshot['translations']['en']['title'][0]['value'] = 'Discovery 2';
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Update, 2))->result);
    $item = $this->newsItem($snapshot['uuid']);
    foreach (['en', 'fr'] as $langcode) {
      $this->assertSame('<p>Français</p>', $item->getTranslation($langcode)->get('field_content')->value);
    }
    $this->assertSame(['Discovery 2', 'Découverte'], [$item->label(), $item->getTranslation('fr')->label()]);
  }

}
