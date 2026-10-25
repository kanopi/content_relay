<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\comment\Tests\CommentTestTrait;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Creates, updates and deletes every syncable type through the applier.
 *
 * Like the base module's AllEntityTypesTest, nothing here names the types it
 * covers: it asks Content Relay which types and bundles it can sync and, for
 * each, applies a create, an update to entirely new sample values and a
 * delete, as events from the origin.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class AllEntityTypesApplyTest extends ReplicaTestBase {

  use CommentTestTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'block_content',
    'comment',
    'contact',
    'datetime',
    'entity_reference_revisions',
    'file',
    'image',
    'language',
    'link',
    'media',
    'media_test_source',
    'menu_link_content',
    'options',
    'paragraphs',
    'path',
    'path_alias',
    'shortcut',
    'telephone',
  ];

  /**
   * Entity types that cannot round-trip by design; see AllEntityTypesTest.
   */
  private const UNSAMPLEABLE = ['entity_test_field_methods'];

  /**
   * Values the sample generator gets wrong for a type, by entity type.
   */
  private const SAMPLE_VALUES = [
    'menu_link_content' => ['menu_name' => 'main', 'parent' => ''],
    // A comment needs a real host; random words for its type fail to save.
    'comment' => ['entity_type' => 'node', 'field_name' => 'comment', 'pid' => NULL],
  ];

  /**
   * Entity types whose fields fail validation whatever their value.
   *
   * The replica validates before saving, so these are refused; the test
   * checks they are refused for that reason.
   */
  private const ALWAYS_INVALID = ['entity_test_constraint_violation'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach ($this->container->get('entity_type.manager')->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ConfigEntityTypeInterface && $definition->getProvider() !== 'contact') {
        try {
          $this->installEntitySchema($id);
        }
        catch (\Throwable) {
          // Installed already, or without storage of its own.
        }
      }
    }
    $this->installSchema('shortcut', ['shortcut_set_users']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['system', 'user', 'comment', 'media', 'shortcut', 'image', 'file', 'language']);
    $this->addDefaultCommentField('node', 'article');
    $storage = fn (string $type) => $this->container->get('entity_type.manager')->getStorage($type);
    $storage('block_content_type')->create(['id' => 'basic', 'label' => 'Basic'])->save();
    $storage('paragraphs_type')->create(['id' => 'text', 'label' => 'Text'])->save();
    $this->createMediaType('test', ['id' => 'document']);
  }

  /**
   * A sample entity's snapshot; the entity itself is deleted.
   */
  private function sample(ContentEntityTypeInterface $entity_type, string $bundle, ?string $id): array {
    $values = self::SAMPLE_VALUES[$entity_type->id()] ?? [];
    foreach (['langcode' => 'en', 'default_langcode' => TRUE] as $key => $value) {
      if ($name = $entity_type->getKey($key)) {
        $values[$name] = $value;
      }
    }
    if ($id !== NULL) {
      $values[(string) $entity_type->getKey('id')] = $id;
    }
    $storage = $this->container->get('entity_type.manager')->getStorage($entity_type->id());
    assert($storage instanceof ContentEntityStorageInterface);
    $entity = $storage->createWithSampleValues($bundle, $values);
    $entity->save();
    $entity = $storage->loadUnchanged($entity->id());
    assert($entity instanceof ContentEntityInterface);
    $snapshot = $this->snapshot($entity);
    $this->forget($entity);
    return $snapshot;
  }

  /**
   * The hash of the local copy, as the origin would compute it.
   */
  private function localHash(string $entity_type_id, string $uuid): ?string {
    $storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);
    $storage->resetCache();
    $entities = $storage->loadByProperties([(string) $storage->getEntityType()->getKey('uuid') => $uuid]);
    $entity = reset($entities);
    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }
    return $this->container->get('content_relay.revision_hasher')->hash($this->snapshot($entity))->entity;
  }

  /**
   * Every syncable type and bundle is created, updated and deleted.
   */
  public function testEveryTypeCreatesUpdatesAndDeletes(): void {
    $types = $this->container->get('content_relay.syncable_entity_types');
    $hasher = $this->container->get('content_relay.revision_hasher');
    $sync_state = $this->container->get('content_relay_replica.sync_state');

    // Composite entities travel inside their parent, never on their own.
    $bundles = [];
    foreach ($types->getEntityTypes(TRUE) as $entity_type_id => $entity_type) {
      if (!in_array($entity_type_id, self::UNSAMPLEABLE, TRUE) && !GenericContentEntityNormalizer::isComposite($entity_type)) {
        $bundles[$entity_type_id] = array_keys($types->getBundles($entity_type_id));
      }
    }
    $this->container->get('content_relay_replica.contracts')->save($this->contract(2, $bundles));

    $covered = [];
    $failures = [];
    foreach ($bundles as $entity_type_id => $bundle_ids) {
      $entity_type = $types->getEntityTypes(TRUE)[$entity_type_id];
      $string_id = $this->container->get('entity_field.manager')->getBaseFieldDefinitions($entity_type_id)[(string) $entity_type->getKey('id')]->getType() !== 'integer';
      foreach ($bundle_ids as $bundle) {
        $label = "$entity_type_id:$bundle";
        try {
          $id = match (TRUE) {
            $entity_type->getKey('id') === $entity_type->getKey('uuid') => $this->container->get('uuid')->generate(),
            $string_id => $this->randomMachineName(),
            default => NULL,
          };
          $created = $this->sample($entity_type, $bundle, $id);
          $updated = $this->sample($entity_type, $bundle, $id);
          $updated['uuid'] = $created['uuid'];
          $event = fn (?array $snapshot, Operation $operation, int $revision) => $this->event($snapshot, $operation, $revision, [
            'entity_type' => $entity_type_id,
            'bundle' => $bundle,
            'entity_uuid' => $created['uuid'],
            'revisionable' => $entity_type->isRevisionable(),
            'contract_version' => 2,
          ]);

          if (in_array($entity_type_id, self::ALWAYS_INVALID, TRUE)) {
            $result = $this->apply($event($created, Operation::Create, 1));
            $this->assertSame([ApplyResult::APPLY_FAILED, 'validation_failed'], [$result->result, $result->code], $label);
            $this->assertNull($this->localHash($entity_type_id, $created['uuid']));
            $covered[] = $label;
            continue;
          }
          $steps = [
            'create' => [$created, Operation::Create, 1, ApplyResult::APPLIED],
            'update' => [$updated, Operation::Update, 2, ApplyResult::APPLIED],
            'delete' => [NULL, Operation::Delete, 3, ApplyResult::DELETED],
          ];
          foreach ($steps as $step => [$snapshot, $operation, $revision, $expected]) {
            $result = $this->apply($event($snapshot, $operation, $revision));
            if ($result->result !== $expected) {
              throw new \RuntimeException(sprintf('%s answered %s: %s %s', $step, $result->result, $result->message, json_encode($result->details['violations'] ?? [])));
            }
            $hash = $this->localHash($entity_type_id, $created['uuid']);
            if ($snapshot === NULL) {
              if ($hash !== NULL) {
                throw new \RuntimeException('still here after the delete');
              }
              continue;
            }
            if ($hash !== $hasher->hash($snapshot)->entity) {
              throw new \RuntimeException("the copy does not hash as the origin's after the $step");
            }
            $state = $sync_state->get($entity_type_id, $created['uuid']);
            if ($state['status'] !== SyncStatus::SYNCED) {
              throw new \RuntimeException("$step left it {$state['status']}: {$state['last_error']}");
            }
          }
          $covered[] = $label;
        }
        catch (\Throwable $e) {
          $failures[$label] = $e->getMessage();
        }
      }
    }

    $this->assertSame([], $failures, 'Every syncable type and bundle is created, updated and deleted.');
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
      'entity_test_mulrev:entity_test_mulrev',
    ] as $expected) {
      $this->assertContains($expected, $covered);
    }
    $this->assertGreaterThan(30, count($covered), implode(', ', $covered));
  }

}
