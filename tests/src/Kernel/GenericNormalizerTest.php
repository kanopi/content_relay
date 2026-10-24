<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\content_relay\Serialization\UuidResolver;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generic normalizer and hashing on real entities.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class GenericNormalizerTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'key',
    'field',
    'filter',
    'node',
    'system',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * The serializer.
   */
  private EntitySerializer $serializer;

  /**
   * The hasher.
   */
  private RevisionHasher $hasher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body', 'label' => 'Body'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $this->createEntityReferenceField('node', 'article', 'field_tags', 'Tags', 'taxonomy_term', 'default', ['target_bundles' => ['tags' => 'tags']], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    $this->serializer = $this->container->get('content_relay.entity_serializer');
    $this->hasher = $this->container->get('content_relay.revision_hasher');
  }

  /**
   * Creates a saved article with two tags.
   */
  private function createArticle(): NodeInterface {
    $tags = [];
    foreach (['One', 'Two'] as $name) {
      $tag = Term::create(['vid' => 'tags', 'name' => $name]);
      $tag->save();
      $tags[] = $tag;
    }
    $node = Node::create([
      'type' => 'article',
      'title' => 'Hello 世界 😀',
      'body' => ['value' => '<p>Body</p>', 'format' => 'plain_text'],
      'field_tags' => $tags,
      'uid' => $this->createUser(),
      'status' => TRUE,
    ]);
    $node->save();
    return $node;
  }

  /**
   * A field's items from a snapshot, wherever the field lives.
   */
  private function getField(array $snapshot, string $name): ?array {
    return $snapshot['shared'][$name] ?? $snapshot['translations'][$snapshot['default_langcode']][$name] ?? NULL;
  }

  /**
   * Sets a field's items in the default translation of a snapshot.
   *
   * Whether a field is shared depends on whether the bundle is translatable,
   * which these tests do not care about.
   */
  private function setField(array &$snapshot, string $name, ?array $items): void {
    $section = isset($snapshot['shared'][$name]) ? 'shared' : 'translations';
    if ($section === 'shared') {
      $snapshot['shared'][$name] = $items;
      if ($items === NULL) {
        unset($snapshot['shared'][$name]);
      }
      return;
    }
    $snapshot['translations'][$snapshot['default_langcode']][$name] = $items;
    if ($items === NULL) {
      unset($snapshot['translations'][$snapshot['default_langcode']][$name]);
    }
  }

  /**
   * The default translation's metadata, wherever it lives.
   */
  private function metadata(array $snapshot): array {
    return $snapshot['metadata']['shared'] + ($snapshot['metadata']['translations'][$snapshot['default_langcode']] ?? []);
  }

  /**
   * A snapshot carries UUIDs and values, never local IDs or revision data.
   */
  public function testSnapshotStructure(): void {
    $node = $this->createArticle();
    $context = new NormalizationContext();
    $snapshot = $this->serializer->normalize($node, $context);

    $this->assertSame('node', $snapshot['entity_type']);
    $this->assertSame('article', $snapshot['bundle']);
    $this->assertSame($node->uuid(), $snapshot['uuid']);
    $this->assertSame('en', $snapshot['default_langcode']);

    $fields = $snapshot['shared'] + $snapshot['translations']['en'];
    $local_fields = [
      'nid', 'vid', 'uuid', 'type', 'langcode', 'default_langcode', 'path',
      'revision_uid', 'revision_timestamp', 'revision_log', 'revision_translation_affected',
    ];
    foreach ($local_fields as $local) {
      $this->assertArrayNotHasKey($local, $fields, "$local is not synced.");
    }
    $this->assertSame([['value' => 'Hello 世界 😀']], $fields['title']);
    $this->assertSame([['value' => TRUE]], $fields['status'], 'Booleans are cast, not left as "1".');
    $this->assertIsInt($fields['created'][0]['value']);
    $this->assertSame([['value' => '<p>Body</p>', 'format' => 'plain_text']], $fields['body'], 'NULL summary is left out.');

    $tags = $fields['field_tags'];
    $this->assertCount(2, $tags);
    $this->assertSame(['target_type' => 'taxonomy_term', 'target_uuid' => $node->get('field_tags')->entity->uuid()], $tags[0]);
    $this->assertSame($node->getOwner()->uuid(), $fields['uid'][0][Snapshot::TARGET_UUID]);
    $this->assertArrayHasKey('changed', $this->metadata($snapshot));
    $this->assertArrayNotHasKey('changed', $fields);

    // Dependencies are collected for queueing before the parent.
    $this->assertArrayHasKey('taxonomy_term:' . $tags[0]['target_uuid'], $context->references);
    $this->assertArrayHasKey('user:' . $node->getOwner()->uuid(), $context->references);
    $this->assertStringNotContainsString('"target_id"', json_encode($snapshot));
  }

  /**
   * The hash is the same in memory and after loading from the database.
   */
  public function testHashIsStableAcrossLoads(): void {
    $node = $this->createArticle();
    $before = $this->hasher->hash($this->serializer->normalize($node));
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $reloaded = Node::load($node->id());
    $this->assertEquals($before, $this->hasher->hash($this->serializer->normalize($reloaded)));

    // Saving a new revision with nothing changed but revision metadata and
    // the changed time leaves the hash alone.
    $reloaded->setNewRevision();
    $reloaded->setRevisionLogMessage('Nothing to see');
    $reloaded->setChangedTime($reloaded->getChangedTime() + 100);
    $reloaded->save();
    $this->assertEquals($before, $this->hasher->hash($this->serializer->normalize($reloaded)));

    // Content changes it.
    $reloaded->setTitle('Changed');
    $this->assertNotEquals($before->entity, $this->hasher->hash($this->serializer->normalize($reloaded))->entity);
  }

  /**
   * A snapshot recreates the entity, and the copy hashes identically.
   *
   * This stands in for the replica: the node is deleted and rebuilt from its
   * snapshot alone, with a different local ID.
   */
  public function testRoundTripRecreatesTheEntity(): void {
    $node = $this->createArticle();
    $snapshot = json_decode(json_encode($this->serializer->normalize($node)), TRUE);
    $original = $this->hasher->hash($snapshot);
    $nid = $node->id();
    $node->delete();

    $context = new DenormalizationContext();
    $copy = $this->serializer->denormalize($snapshot, $context);
    $this->assertTrue($copy->isNew());
    assert($copy instanceof NodeInterface);
    $copy->save();
    $this->assertSame([], $context->missingReferences);
    $this->assertNotEquals($nid, $copy->id());
    $this->assertSame($snapshot['uuid'], $copy->uuid());
    $this->assertSame('Hello 世界 😀', $copy->label());
    $this->assertSame((int) $this->metadata($snapshot)['changed'][0]['value'], (int) $copy->getChangedTime());

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertEquals($original, $this->hasher->hash($this->serializer->normalize(Node::load($copy->id()))));
  }

  /**
   * Updating applies changes and clears fields the snapshot leaves out.
   */
  public function testUpdateExistingEntity(): void {
    $node = $this->createArticle();
    $snapshot = $this->serializer->normalize($node);
    $this->setField($snapshot, 'title', [['value' => 'From origin']]);
    $this->setField($snapshot, 'field_tags', NULL);

    $updated = $this->serializer->denormalize($snapshot);
    $this->assertSame($node->id(), $updated->id());
    $this->assertSame('From origin', $updated->label());
    $this->assertTrue($updated->get('field_tags')->isEmpty());
  }

  /**
   * Local-only fields are never written.
   */
  public function testPreservedFieldsAreKept(): void {
    $node = $this->createArticle();
    $snapshot = $this->serializer->normalize($node);
    $this->setField($snapshot, 'title', [['value' => 'From origin']]);
    $updated = $this->serializer->denormalize($snapshot, new DenormalizationContext(preserveFields: ['title']));
    $this->assertSame('Hello 世界 😀', $updated->label());
  }

  /**
   * References to entities that do not exist here are reported, not guessed.
   */
  public function testMissingReferencesAreReported(): void {
    $node = $this->createArticle();
    $snapshot = $this->serializer->normalize($node);
    $missing = '7d1b2c3a-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
    $tags = $this->getField($snapshot, 'field_tags');
    $tags[] = ['target_type' => 'taxonomy_term', 'target_uuid' => $missing];
    $this->setField($snapshot, 'field_tags', $tags);
    $snapshot['shared']['field_unknown'] = [['value' => 1]];

    $context = new DenormalizationContext();
    $updated = $this->serializer->denormalize($snapshot, $context);
    $this->assertArrayHasKey('taxonomy_term:' . $missing, $context->missingReferences);
    $this->assertSame('field_tags', $context->missingReferences['taxonomy_term:' . $missing]['field']);
    $this->assertCount(2, $updated->get('field_tags'));
    $this->assertArrayHasKey('field_unknown', $context->unknownFields);
  }

  /**
   * The anonymous user is matched by role, not by its per-site UUID.
   */
  public function testAnonymousOwner(): void {
    $node = $this->createArticle();
    $node->setOwnerId(0)->save();
    $snapshot = $this->serializer->normalize($node);
    $owner = $this->getField($snapshot, 'uid')[0];
    $this->assertSame('anonymous', $owner[Snapshot::TARGET_UUID]);

    $node->setOwnerId(1);
    $applied = $this->serializer->denormalize($snapshot, NULL, $node);
    assert($applied instanceof NodeInterface);
    $this->assertSame(0, (int) $applied->getOwnerId());
  }

  /**
   * Dangling references are reported on the sending side and left out.
   */
  public function testDanglingReferences(): void {
    $node = $this->createArticle();
    $node->get('field_tags')->appendItem(['target_id' => 9999]);
    $context = new NormalizationContext();
    $snapshot = $this->serializer->normalize($node, $context);
    $this->assertCount(2, $this->getField($snapshot, 'field_tags'));
    $this->assertSame([['field' => 'field_tags', 'entity_type' => 'taxonomy_term', 'id' => 9999]], $context->unresolved);
  }

  /**
   * A snapshot only updates the entity it describes.
   */
  public function testMismatchedTargetIsRefused(): void {
    $one = $this->createArticle();
    $two = $this->createArticle();
    $this->expectException(\InvalidArgumentException::class);
    $this->serializer->denormalize($this->serializer->normalize($one), NULL, $two);
  }

  /**
   * UUID lookups are batched: one query per entity type, then cached.
   */
  public function testReferenceLookupsAreBatched(): void {
    $node = $this->createArticle();
    $resolver = new class($this->container->get('entity_type.manager'), $this->container->get('database')) extends UuidResolver {

      /**
       * Entity types queried, one entry per query.
       *
       * @var string[]
       */
      public array $queries = [];

      /**
       * {@inheritdoc}
       */
      protected function query(string $entity_type_id, string $by, array $values): array {
        $this->queries[] = $entity_type_id;
        return parent::query($entity_type_id, $by, $values);
      }

    };
    $this->container->set('content_relay.uuid_resolver', $resolver);
    $this->container->set('content_relay.entity_serializer', NULL);
    $snapshot = $this->container->get('content_relay.entity_serializer')->normalize($node);
    $queried = $resolver->queries;
    sort($queried);
    $this->assertSame(['taxonomy_term', 'user'], $queried);

    $this->assertNull($resolver->toUuid('taxonomy_term', 404));
    $tag = $node->get('field_tags')->entity;
    $this->assertSame((int) $tag->id(), (int) $resolver->toId('taxonomy_term', $tag->uuid()), 'Known from the earlier query.');
    $this->assertCount(3, $resolver->queries, 'Only the unknown ID needed a query.');
    $this->assertNotEmpty($snapshot);
  }

}
