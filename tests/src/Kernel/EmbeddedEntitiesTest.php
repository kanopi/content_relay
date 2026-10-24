<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests composite entities (paragraphs) embedded in their parent's snapshot.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class EmbeddedEntitiesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_translation',
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'key',
    'language',
    'node',
    'paragraphs',
    'system',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ParagraphsType::create(['id' => 'text', 'label' => 'Text'])->save();
    ParagraphsType::create(['id' => 'container', 'label' => 'Container'])->save();
    $this->addField('paragraph', 'text', 'field_text', 'string', [], TRUE);
    $this->addField('paragraph', 'container', 'field_children', 'entity_reference_revisions', ['target_type' => 'paragraph']);
    $this->addField('node', 'article', 'field_sections', 'entity_reference_revisions', ['target_type' => 'paragraph']);

    $translation = $this->container->get('content_translation.manager');
    $translation->setEnabled('node', 'article', TRUE);
    $translation->setEnabled('paragraph', 'text', TRUE);
    $translation->setEnabled('paragraph', 'container', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();
  }

  /**
   * Adds a field.
   */
  private function addField(string $entity_type, string $bundle, string $name, string $type, array $settings, bool $translatable = FALSE): void {
    FieldStorageConfig::create([
      'entity_type' => $entity_type,
      'field_name' => $name,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'field_name' => $name,
      'translatable' => $translatable,
    ])->save();
  }

  /**
   * A node with a container holding a container holding two text paragraphs.
   */
  private function tree(): NodeInterface {
    $leaves = [
      Paragraph::create(['type' => 'text', 'field_text' => 'First']),
      Paragraph::create(['type' => 'text', 'field_text' => 'Second']),
    ];
    $inner = Paragraph::create(['type' => 'container', 'field_children' => $leaves]);
    $outer = Paragraph::create(['type' => 'container', 'field_children' => [$inner]]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Tree',
      'uid' => 0,
      'langcode' => 'en',
      'field_sections' => [$outer, Paragraph::create(['type' => 'text', 'field_text' => 'Last'])],
    ]);
    $node->save();
    return $node;
  }

  /**
   * The paragraph at a delta of a node's sections.
   */
  private function paragraph(NodeInterface $node, int $delta): Paragraph {
    $paragraph = $node->get('field_sections')->get($delta)?->get('entity')->getValue();
    assert($paragraph instanceof Paragraph);
    return $paragraph;
  }

  /**
   * The text values in document order, through three levels.
   */
  private function texts(NodeInterface $node): array {
    $texts = [];
    $walk = function ($items) use (&$walk, &$texts): void {
      foreach ($items as $item) {
        $paragraph = $item->get('entity')->getValue();
        if ($paragraph->hasField('field_text')) {
          $texts[] = $paragraph->get('field_text')->value;
        }
        else {
          $walk($paragraph->get('field_children'));
        }
      }
    };
    $walk($node->get('field_sections'));
    return $texts;
  }

  /**
   * Embedded snapshots carry the whole tree, with no local IDs or parents.
   */
  public function testSnapshotEmbedsTheTree(): void {
    $node = $this->tree();
    $context = new NormalizationContext();
    $snapshot = $this->container->get('content_relay.entity_serializer')->normalize($node, $context);
    $outer = $snapshot['shared']['field_sections'][0][Snapshot::EMBEDDED];
    $inner = $outer['shared']['field_children'][0][Snapshot::EMBEDDED];
    $leaf = $inner['shared']['field_children'][1][Snapshot::EMBEDDED];
    $this->assertSame(['paragraph', 'text', 'Second'], [
      $leaf['entity_type'],
      $leaf['bundle'],
      $leaf['translations']['en']['field_text'][0]['value'],
    ]);
    $this->assertSame($this->paragraph($node, 0)->uuid(), $outer['uuid']);

    $json = json_encode($snapshot);
    foreach (['target_id', 'target_revision_id', 'parent_id', 'parent_type', 'parent_field_name'] as $local) {
      $this->assertStringNotContainsString('"' . $local . '"', $json);
    }
    $this->assertSame([], $context->references, 'Embedded entities are not separate dependencies.');

    // Paragraphs are never synced on their own.
    $this->assertArrayNotHasKey('paragraph', $this->container->get('content_relay.syncable_entity_types')->getEntityTypes());
  }

  /**
   * The tree is rebuilt with the same UUIDs and hashes the same.
   */
  public function testTreeRoundTrips(): void {
    $node = $this->tree();
    $serializer = $this->container->get('content_relay.entity_serializer');
    $hasher = $this->container->get('content_relay.revision_hasher');
    $snapshot = json_decode(json_encode($serializer->normalize($node)), TRUE);
    $uuids = [$this->paragraph($node, 0)->uuid(), $this->paragraph($node, 1)->uuid()];
    $node->delete();
    $this->container->get('entity_type.manager')->getStorage('paragraph')->delete($this->container->get('entity_type.manager')->getStorage('paragraph')->loadMultiple());
    $this->container->get('content_relay.uuid_resolver')->reset();

    $copy = $serializer->denormalize($snapshot);
    $copy->save();
    $saved = Node::load($copy->id());
    $this->assertSame(['First', 'Second', 'Last'], $this->texts($saved));
    $this->assertSame($uuids, [
      $this->paragraph($saved, 0)->uuid(),
      $this->paragraph($saved, 1)->uuid(),
    ]);
    $this->assertEquals($hasher->hash($snapshot), $hasher->hash($serializer->normalize($saved)));
  }

  /**
   * Reordering, editing and removing paragraphs is applied as a new revision.
   */
  public function testReorderAndRemove(): void {
    $node = $this->tree();
    $serializer = $this->container->get('content_relay.entity_serializer');
    $snapshot = $serializer->normalize($node);
    $removed = $this->paragraph($node, 0)->uuid();

    // On the origin: the container is removed, "Last" edited and a new
    // paragraph added before it.
    $last = $snapshot['shared']['field_sections'][1];
    $last[Snapshot::EMBEDDED]['translations']['en']['field_text'][0]['value'] = 'Last, edited';
    $new = $serializer->normalize(Paragraph::create(['type' => 'text', 'field_text' => 'New', 'langcode' => 'en']));
    $snapshot['shared']['field_sections'] = [[Snapshot::EMBEDDED => $new, Snapshot::TARGET_TYPE => 'paragraph'], $last];

    $updated = $serializer->denormalize($snapshot, NULL, $node);
    $updated->setNewRevision();
    $updated->save();
    $saved = Node::load($node->id());
    $this->assertSame(['New', 'Last, edited'], $this->texts($saved));
    $this->assertNotContains($removed, [$this->paragraph($saved, 0)->uuid(), $this->paragraph($saved, 1)->uuid()]);
    $this->assertSame($last[Snapshot::EMBEDDED]['uuid'], $this->paragraph($saved, 1)->uuid(), 'The edited paragraph is the same entity.');
  }

  /**
   * Paragraph translations travel with the parent's translations.
   */
  public function testTranslatedParagraphs(): void {
    $node = $this->tree();
    $node->addTranslation('fr', ['title' => 'Arbre'])->save();
    $last = $this->paragraph($node, 1);
    $last->addTranslation('fr', ['field_text' => 'Dernier'])->save();
    $node = Node::load($node->id());

    $serializer = $this->container->get('content_relay.entity_serializer');
    $hasher = $this->container->get('content_relay.revision_hasher');
    $snapshot = $serializer->normalize($node);
    $embedded = $snapshot['shared']['field_sections'][1][Snapshot::EMBEDDED];
    $this->assertSame('Dernier', $embedded['translations']['fr']['field_text'][0]['value']);

    // Changing the French paragraph changes only the French hash.
    $before = $hasher->hash($snapshot);
    $snapshot['shared']['field_sections'][1][Snapshot::EMBEDDED]['translations']['fr']['field_text'][0]['value'] = 'Fin';
    $this->assertSame(['fr'], $before->differingTranslations($hasher->hash($snapshot)));

    $updated = $serializer->denormalize($snapshot, NULL, $node);
    $updated->save();
    $saved = Node::load($node->id());
    $this->assertSame('Fin', $this->paragraph($saved, 1)->getTranslation('fr')->get('field_text')->value);
    $this->assertSame('Last', $this->paragraph($saved, 1)->get('field_text')->value);
  }

}
