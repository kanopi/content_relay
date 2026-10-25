<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Serialization\LatestRevision;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests synced content under Content Moderation.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ModerationTest extends ReplicaTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language', 'content_translation', 'workflows', 'content_moderation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['language', 'content_moderation']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')->setLanguageAlterable(TRUE)->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $workflow = $this->createEditorialWorkflow();
    $type = $workflow->getTypePlugin();
    assert($type instanceof ContentModerationInterface);
    $type->addEntityTypeAndBundle('node', 'article');
    $workflow->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * An origin article's latest revision, as the origin sends it.
   *
   * @param array<string, array{0: string, 1: string}> $translations
   *   Title and moderation state by langcode, the default first.
   * @param string|null $uuid
   *   The UUID of an item this site may have, for a later version of it.
   */
  private function moderated(array $translations, ?string $uuid = NULL): array {
    $default = array_key_first($translations);
    [$title, $state] = $translations[$default];
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'langcode' => $default,
      'moderation_state' => $state,
      'uid' => 0,
    ]);
    $node->save();
    foreach (array_slice($translations, 1, NULL, TRUE) as $langcode => [$title, $state]) {
      $node = LatestRevision::of(Node::load($node->id()), $this->container->get('entity_type.manager'));
      $node->addTranslation($langcode, ['title' => $title, 'moderation_state' => $state])->save();
    }
    $latest = LatestRevision::of(Node::load($node->id()), $this->container->get('entity_type.manager'));
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($latest)), TRUE);
    $node->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    if ($uuid !== NULL) {
      $snapshot['uuid'] = $uuid;
    }
    return $snapshot;
  }

  /**
   * The latest revision here.
   */
  private function latest(string $uuid): NodeInterface {
    $node = LatestRevision::of($this->local($uuid), $this->container->get('entity_type.manager'));
    assert($node instanceof NodeInterface);
    return $node;
  }

  /**
   * Moderation states per translation.
   */
  private function states(NodeInterface $node): array {
    $states = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $states[$langcode] = $node->getTranslation($langcode)->get('moderation_state')->value;
    }
    ksort($states);
    return $states;
  }

  /**
   * Each translation keeps the origin's state.
   */
  public function testStatesPerTranslation(): void {
    $snapshot = $this->moderated(['en' => ['Hello', 'published'], 'fr' => ['Bonjour', 'draft']]);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->assertSame(['en' => 'published', 'fr' => 'draft'], $this->states($this->latest($snapshot['uuid'])));
    $this->assertTrue($this->local($snapshot['uuid'])->isPublished());
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
  }

  /**
   * A draft on top of published content arrives as a draft here too.
   */
  public function testForwardDraft(): void {
    $published = $this->moderated(['en' => ['Live', 'published']]);
    $this->apply($this->event($published, Operation::Create, 1));
    $draft = $this->moderated(['en' => ['Work in progress', 'draft']], $published['uuid']);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($draft, Operation::Update, 2))->result);

    // The live version stays; the draft is the latest revision.
    $this->assertSame('Live', $this->local($published['uuid'])->label());
    $this->assertSame('Work in progress', $this->latest($published['uuid'])->label());
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $published['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);

    // Published on the origin, it goes live here.
    $live = $this->moderated(['en' => ['Work done', 'published']], $published['uuid']);
    $this->apply($this->event($live, Operation::Update, 3));
    $this->assertSame('Work done', $this->local($published['uuid'])->label());
  }

  /**
   * A target state here replaces the origin's, and does not count.
   */
  public function testTargetState(): void {
    $this->config('content_relay_replica.settings')->set('moderation.target_state', 'draft')->save();
    $snapshot = $this->moderated(['en' => ['Hello', 'published']]);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(['en' => 'draft'], $this->states($this->latest($snapshot['uuid'])));
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], (string) $state['last_error']);
  }

  /**
   * With "draft", the origin's version waits as a draft over local edits.
   */
  public function testDraftConflict(): void {
    $this->useFakeOrigin();
    $this->config('content_relay_replica.settings')->set('conflicts.on_event', 'draft')->save();
    $snapshot = $this->moderated(['en' => ['From origin', 'published']]);
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $local = $this->local($snapshot['uuid']);
    $local->setTitle('Edited here')->set('moderation_state', 'published')->save();

    $update = $this->moderated(['en' => ['Origin update', 'published']], $snapshot['uuid']);
    $result = $this->apply($this->event($update, Operation::Update, 2));
    $this->assertSame('conflict_overwritten', $result->code);
    $this->assertSame('Edited here', $this->local($snapshot['uuid'])->label(), 'The edit stays live.');
    $this->assertSame('Origin update', $this->latest($snapshot['uuid'])->label());
    $this->assertSame('draft', $this->latest($snapshot['uuid'])->get('moderation_state')->value);
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::CONFLICT, $state['status']);
    $this->assertSame('draft', $state['notes']['conflict_mode']);

    // Accepting publishes the origin's version.
    $this->serveItem($update, 2);
    $this->container->get('content_relay_replica.conflicts')->acceptOrigin('node', $snapshot['uuid']);
    $this->assertSame('Origin update', $this->local($snapshot['uuid'])->label());
    $this->assertSame(SyncStatus::SYNCED, $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['status']);
  }

}
