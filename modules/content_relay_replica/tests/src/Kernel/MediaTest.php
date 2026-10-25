<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests media and terms as dependencies, translated and failing.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MediaTest extends ReplicaTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'file',
    'image',
    'language',
    'media',
    'media_test_source',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['language', 'file', 'image', 'media']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createMediaType('test', ['id' => 'document']);
    $this->createEntityReferenceField('node', 'article', 'field_media', 'Media', 'media', 'default', ['target_bundles' => ['document' => 'document']]);
    foreach (['node' => 'article', 'taxonomy_term' => 'tags', 'media' => 'document'] as $entity_type_id => $bundle) {
      ContentLanguageSettings::loadByEntityTypeBundle($entity_type_id, $bundle)
        ->setLanguageAlterable(TRUE)
        ->setThirdPartySetting('content_translation', 'enabled', TRUE)
        ->save();
      $this->container->get('content_translation.manager')->setEnabled($entity_type_id, $bundle, TRUE);
    }
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('content_relay_replica.contracts')->save($this->contract(2, [
      'node' => ['article'],
      'taxonomy_term' => ['tags'],
      'media' => ['document'],
    ]));
  }

  /**
   * A media item as the origin has it, with an optional French name.
   */
  private function originMedia(string $name, ?string $french = NULL): MediaInterface {
    $media = Media::create([
      'bundle' => 'document',
      'name' => $name,
      'field_media_test' => 'document.pdf',
      'langcode' => 'en',
    ]);
    if ($french !== NULL) {
      $media->addTranslation('fr', ['name' => $french, 'field_media_test' => 'document.pdf']);
    }
    $media->save();
    return $media;
  }

  /**
   * A term as the origin has it, with an optional French name.
   */
  private function originTerm(string $name, ?string $french = NULL): TermInterface {
    $term = Term::create(['vid' => 'tags', 'name' => $name, 'langcode' => 'en']);
    if ($french !== NULL) {
      $term->addTranslation('fr', ['name' => $french]);
    }
    $term->save();
    return $term;
  }

  /**
   * Builds the event for a media item's snapshot.
   */
  private function mediaEvent(array $snapshot, Operation $operation, int $revision): mixed {
    return $this->event($snapshot, $operation, $revision, [
      'entity_type' => 'media',
      'bundle' => 'document',
      'contract_version' => 2,
    ]);
  }

  /**
   * The sync state of an item.
   */
  private function state(string $entity_type_id, string $uuid): ?array {
    return $this->container->get('content_relay_replica.sync_state')->get($entity_type_id, $uuid);
  }

  /**
   * Loads an entity here by UUID, fresh from storage.
   */
  private function loadHere(string $entity_type_id, string $uuid): mixed {
    $this->container->get('entity_type.manager')->getStorage($entity_type_id)->resetCache();
    return $this->container->get('entity.repository')->loadEntityByUuid($entity_type_id, $uuid);
  }

  /**
   * Translated terms and media arrive, and a translated node finds them.
   */
  public function testTranslatedTermAndMedia(): void {
    $term = $this->originTerm('Science', 'Sciences');
    $media = $this->originMedia('Report', 'Rapport');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Findings',
      'langcode' => 'en',
      'uid' => 0,
      'field_tags' => [$term],
      'field_media' => [$media],
    ]);
    $node->addTranslation('fr', ['title' => 'Résultats', 'field_tags' => [$term], 'field_media' => [$media]]);
    $node->save();
    [$term_snapshot, $media_snapshot, $node_snapshot] = [
      $this->snapshot($term),
      $this->snapshot($media),
      $this->snapshot($node),
    ];
    $this->forget($node, $media, $term);

    $term_event = $this->event($term_snapshot, Operation::Create, 1, [
      'entity_type' => 'taxonomy_term',
      'bundle' => 'tags',
      'contract_version' => 2,
    ]);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($term_event)->result);
    $result = $this->apply($this->mediaEvent($media_snapshot, Operation::Create, 1));
    $this->assertSame(ApplyResult::APPLIED, $result->result, (string) $result->message);
    $result = $this->apply($this->event($node_snapshot, Operation::Create, 1, ['contract_version' => 2]));
    $this->assertSame(ApplyResult::APPLIED, $result->result, (string) $result->message);

    $local_term = $this->loadHere('taxonomy_term', $term_snapshot['uuid']);
    $local_media = $this->loadHere('media', $media_snapshot['uuid']);
    $this->assertSame('Sciences', $local_term->getTranslation('fr')->label());
    $this->assertSame('Rapport', $local_media->getTranslation('fr')->label());
    foreach (['taxonomy_term' => $term_snapshot, 'media' => $media_snapshot, 'node' => $node_snapshot] as $entity_type_id => $snapshot) {
      $state = $this->state($entity_type_id, $snapshot['uuid']);
      $this->assertSame(SyncStatus::SYNCED, $state['status'], $entity_type_id . ': ' . $state['last_error']);
      $this->assertSame(['en', 'fr'], array_keys($state['source_translation_hashes']));
    }

    // Both translations of the node point at the copies here, which have
    // the reader's language.
    $local_node = $this->local($node_snapshot['uuid']);
    foreach (['en' => ['Science', 'Report'], 'fr' => ['Sciences', 'Rapport']] as $langcode => [
      $term_label,
      $media_label,
    ]) {
      $translation = $local_node->getTranslation($langcode);
      $this->assertSame((string) $local_term->id(), (string) $translation->get('field_tags')->target_id);
      $this->assertSame((string) $local_media->id(), (string) $translation->get('field_media')->target_id);
      $this->assertSame($term_label, $local_term->getTranslation($langcode)->label());
      $this->assertSame($media_label, $local_media->getTranslation($langcode)->label());
    }

    // A French name changed on the origin changes only French here.
    $media = $this->originMedia('Report', 'Rapport annuel');
    $renamed = $this->snapshot($media);
    $this->forget($media);
    $renamed['uuid'] = $media_snapshot['uuid'];
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->mediaEvent($renamed, Operation::Update, 2))->result);
    $local_media = $this->loadHere('media', $media_snapshot['uuid']);
    $this->assertSame(['Report', 'Rapport annuel'], [$local_media->label(), $local_media->getTranslation('fr')->label()]);
  }

  /**
   * A node waits for its media item, also while that item fails to save.
   */
  public function testNodeWaitsForMedia(): void {
    $media = $this->originMedia('Report');
    $node = Node::create(['type' => 'article', 'title' => 'Illustrated', 'uid' => 0, 'field_media' => [$media]]);
    $node->save();
    [$node_snapshot, $media_snapshot] = [$this->snapshot($node), $this->snapshot($media)];
    $this->forget($node, $media);

    $result = $this->apply($this->event($node_snapshot, Operation::Create, 1, ['contract_version' => 2]));
    $this->assertSame([202, 'waiting_dependency'], [$result->httpStatus, $result->code]);
    $this->assertSame([['entity_type' => 'media', 'uuid' => $media_snapshot['uuid']]], $result->details['dependencies']);

    // The media item fails to save; the node keeps waiting.
    $broken = $media_snapshot;
    $broken['translations']['en']['name'][0]['value'] = 'Explode';
    $result = $this->apply($this->mediaEvent($broken, Operation::Create, 1));
    $this->assertSame([422, 'save_failed'], [$result->httpStatus, $result->code]);
    $this->runRetryQueue();
    $this->assertNull($this->local($node_snapshot['uuid']));
    $waiting = $this->container->get('content_relay_replica.failed_events')->getOpenForEntity('node', $node_snapshot['uuid']);
    $this->assertSame([FailedEventStore::DEPENDENCY, 'waiting_dependency'], [
      $waiting[0]['failure_class'],
      $waiting[0]['error_code'],
    ]);

    // Once it saves, the node follows, pointing at it.
    $fixed = $media_snapshot;
    $fixed['translations']['en']['name'][0]['value'] = 'Fixed';
    $result = $this->apply($this->mediaEvent($fixed, Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result, (string) $result->message);
    $this->runRetryQueue();
    $node = $this->local($node_snapshot['uuid']);
    $this->assertNotNull($node);
    $this->assertSame('Fixed', $node->get('field_media')->entity->label());
    $this->assertSame(SyncStatus::SYNCED, $this->state('node', $node_snapshot['uuid'])['status']);
  }

  /**
   * Terms and media are updated and deleted like nodes.
   */
  public function testUpdateAndDelete(): void {
    $term = $this->originTerm('Science');
    $media = $this->originMedia('Report');
    [$term_snapshot, $media_snapshot] = [$this->snapshot($term), $this->snapshot($media)];
    $this->forget($media, $term);
    $term_event = fn (?array $snapshot, Operation $operation, int $revision) => $this->event($snapshot, $operation, $revision, [
      'entity_type' => 'taxonomy_term',
      'bundle' => 'tags',
      'entity_uuid' => $term_snapshot['uuid'],
      'contract_version' => 2,
    ]);
    $this->apply($term_event($term_snapshot, Operation::Create, 1));
    $this->apply($this->mediaEvent($media_snapshot, Operation::Create, 1));

    $term_snapshot['translations']['en']['name'][0]['value'] = 'Natural science';
    $media_snapshot['translations']['en']['name'][0]['value'] = 'Annual report';
    $this->assertSame(ApplyResult::APPLIED, $this->apply($term_event($term_snapshot, Operation::Update, 2))->result);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->mediaEvent($media_snapshot, Operation::Update, 2))->result);
    $this->assertSame('Natural science', $this->loadHere('taxonomy_term', $term_snapshot['uuid'])->label());
    $this->assertSame('Annual report', $this->loadHere('media', $media_snapshot['uuid'])->label());

    $this->assertSame(ApplyResult::DELETED, $this->apply($term_event(NULL, Operation::Delete, 3))->result);
    $media_delete = $this->event(NULL, Operation::Delete, 3, [
      'entity_type' => 'media',
      'bundle' => 'document',
      'entity_uuid' => $media_snapshot['uuid'],
      'contract_version' => 2,
    ]);
    $this->assertSame(ApplyResult::DELETED, $this->apply($media_delete)->result);
    $this->assertNull($this->loadHere('taxonomy_term', $term_snapshot['uuid']));
    $this->assertNull($this->loadHere('media', $media_snapshot['uuid']));
  }

}
