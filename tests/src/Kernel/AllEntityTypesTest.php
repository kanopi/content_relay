<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\comment\Tests\CommentTestTrait;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Round-trips every syncable content entity type and bundle in the site.
 *
 * Nothing here names the entity types it covers. It enables a wide set of
 * core and contrib modules and the dozens of entity types in entity_test,
 * asks Content Relay which types and bundles it can sync, and for each one:
 * creates an entity with Drupal's own sample values for every field, takes
 * its snapshot, deletes it, rebuilds it from the snapshot alone and checks
 * the rebuilt copy hashes exactly as the original did.
 *
 * A type that cannot be synced generically shows up here as a failure, not
 * as a gap in a list.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class AllEntityTypesTest extends KernelTestBase {

  use CommentTestTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'block_content',
    'comment',
    'content_relay',
    'contact',
    'datetime',
    'entity_reference_revisions',
    'entity_test',
    'field',
    'file',
    'filter',
    'image',
    'key',
    'language',
    'link',
    'media',
    'media_test_source',
    'menu_link_content',
    'node',
    'options',
    'paragraphs',
    'path',
    'path_alias',
    'shortcut',
    'system',
    'taxonomy',
    'telephone',
    'text',
    'user',
  ];

  /**
   * Entity types that cannot round-trip by design, with the reason.
   */
  private const UNSAMPLEABLE = [
    // Its field methods rewrite values on every save, by design, to test
    // that they run. A real type doing that would be reported as an error on
    // the replica, which is right, but it cannot pass a round trip.
    'entity_test_field_methods',
  ];

  /**
   * Values the sample generator gets wrong for a type, by entity type.
   *
   * Random text is fine for most fields; a few need values that exist.
   */
  private const SAMPLE_VALUES = [
    'menu_link_content' => ['menu_name' => 'main', 'parent' => ''],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $entity_type_manager = $this->container->get('entity_type.manager');
    foreach ($entity_type_manager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ConfigEntityTypeInterface && $definition->getProvider() !== 'contact') {
        try {
          $this->installEntitySchema($id);
        }
        catch (\Throwable) {
          // Types without storage of their own.
        }
      }
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('shortcut', ['shortcut_set_users']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installSchema('content_relay', ['content_relay_seen_message']);
    $this->installConfig([
      'field',
      'filter',
      'node',
      'system',
      'user',
      'comment',
      'media',
      'shortcut',
      'image',
      'file',
      'language',
    ]);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->addDefaultCommentField('node', 'article');
    $this->container->get('entity_type.manager')->getStorage('taxonomy_vocabulary')->create([
      'vid' => 'tags',
      'name' => 'Tags',
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('block_content_type')->create([
      'id' => 'basic',
      'label' => 'Basic',
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('paragraphs_type')->create([
      'id' => 'text',
      'label' => 'Text',
    ])->save();
    $this->createMediaType('test', ['id' => 'document']);
  }

  /**
   * Every syncable type and bundle survives the trip with an identical hash.
   */
  public function testEveryTypeRoundTrips(): void {
    $types = $this->container->get('content_relay.syncable_entity_types');
    $serializer = $this->container->get('content_relay.entity_serializer');
    $hasher = $this->container->get('content_relay.revision_hasher');
    $entity_type_manager = $this->container->get('entity_type.manager');

    $covered = [];
    $failures = [];
    foreach ($types->getEntityTypes(TRUE) as $entity_type_id => $entity_type) {
      if (in_array($entity_type_id, self::UNSAMPLEABLE, TRUE)) {
        continue;
      }
      $storage = $entity_type_manager->getStorage($entity_type_id);
      assert($storage instanceof ContentEntityStorageInterface);
      foreach (array_keys($types->getBundles($entity_type_id)) as $bundle) {
        $label = "$entity_type_id:$bundle";
        try {
          $values = self::SAMPLE_VALUES[$entity_type_id] ?? [];
          // The generator gives default_langcode a random boolean, and an
          // entity saved with FALSE there has no default translation row and
          // loads with every field empty. The language keys are pinned.
          foreach (['langcode' => 'en', 'default_langcode' => TRUE] as $key => $value) {
            if ($name = $entity_type->getKey($key)) {
              $values[$name] = $value;
            }
          }
          $id_key = (string) $entity_type->getKey('id');
          if ($this->container->get('entity_field.manager')->getBaseFieldDefinitions($entity_type_id)[$id_key]->getType() !== 'integer') {
            // Nothing generates string IDs; the creator chooses one.
            $values[$id_key] = $this->randomMachineName();
          }
          $entity = $storage->createWithSampleValues($bundle, $values);
          assert($entity instanceof ContentEntityInterface);
          $entity->save();
        }
        catch (\Throwable $e) {
          $failures[$label] = 'sample entity could not be saved: ' . $e->getMessage();
          continue;
        }
        try {
          $original = $storage->loadUnchanged($entity->id());
          assert($original instanceof ContentEntityInterface);
          $snapshot = json_decode(json_encode($serializer->normalize($original, new NormalizationContext())), TRUE);
          $this->assertNoLocalIds($snapshot, $label);
          $hash = $hasher->hash($snapshot);

          $original->delete();
          $this->container->get('content_relay.uuid_resolver')->reset();
          $copy = $serializer->denormalize($snapshot);
          $this->assertTrue($copy->isNew(), "$label was rebuilt as a new entity.");
          $copy->save();
          $this->container->get('content_relay.uuid_resolver')->reset();

          $rebuilt = $storage->loadUnchanged($copy->id());
          assert($rebuilt instanceof ContentEntityInterface);
          $this->assertSame($snapshot['uuid'], $rebuilt->uuid());
          $again = $serializer->normalize($rebuilt);
          if ($hasher->hash($again)->entity !== $hash->entity) {
            $failures[$label] = 'hash differs after the round trip: ' . json_encode(array_diff_key($this->flatten($again), $this->flatten($snapshot)) + array_diff_key($this->flatten($snapshot), $this->flatten($again)));
            continue;
          }
          $covered[] = $label;
        }
        catch (\Throwable $e) {
          $failures[$label] = get_class($e) . ': ' . $e->getMessage();
        }
      }
    }

    $this->assertSame([], $failures, 'Every syncable type and bundle round-trips.');
    // The run is only meaningful if it reached well beyond the usual suspects.
    foreach ([
      'node:article',
      'taxonomy_term:tags',
      'media:document',
      'block_content:basic',
      'comment:comment',
      'user:user',
      'file:file',
      'path_alias:path_alias',
      'menu_link_content:menu_link_content',
      'shortcut:default',
      'paragraph:text',
      'entity_test_mulrev:entity_test_mulrev',
    ] as $expected) {
      $this->assertContains($expected, $covered);
    }
    $this->assertGreaterThan(30, count($covered), implode(', ', $covered));
  }

  /**
   * Checks a snapshot carries no local content entity IDs.
   */
  private function assertNoLocalIds(array $snapshot, string $label): void {
    $config_types = array_keys(array_filter($this->container->get('entity_type.manager')->getDefinitions(), fn ($d) => $d instanceof ConfigEntityTypeInterface));
    array_walk_recursive($snapshot, function () {});
    $walk = function (array $value) use (&$walk, $config_types, $label): void {
      if (isset($value['target_id']) && isset($value[Snapshot::TARGET_TYPE])) {
        $this->assertContains($value[Snapshot::TARGET_TYPE], $config_types, "$label carries a local ID for a {$value[Snapshot::TARGET_TYPE]}.");
      }
      $this->assertArrayNotHasKey('target_revision_id', $value, "$label carries a local revision ID.");
      foreach ($value as $nested) {
        if (is_array($nested)) {
          $walk($nested);
        }
      }
    };
    $walk($snapshot);
  }

  /**
   * Flattens a snapshot to dotted paths, to show differences.
   */
  private function flatten(array $value, string $prefix = ''): array {
    $flat = [];
    foreach ($value as $key => $item) {
      $path = $prefix === '' ? (string) $key : "$prefix.$key";
      if (is_array($item) && $item !== []) {
        $flat += $this->flatten($item, $path);
      }
      else {
        $flat[$path] = $item;
      }
    }
    return $flat;
  }

}
