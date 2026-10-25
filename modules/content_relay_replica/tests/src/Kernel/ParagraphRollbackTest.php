<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a failed save leaves an item's paragraphs as they were.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ParagraphRollbackTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_reference_revisions', 'file', 'paragraphs'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('paragraph');
    ParagraphsType::create(['id' => 'text', 'label' => 'Text'])->save();
    ParagraphsType::create(['id' => 'container', 'label' => 'Container'])->save();
    $this->addField('paragraph', 'text', 'field_text', 'string', []);
    $this->addField('paragraph', 'container', 'field_children', 'entity_reference_revisions', ['target_type' => 'paragraph']);
    $this->addField('node', 'article', 'field_sections', 'entity_reference_revisions', ['target_type' => 'paragraph']);
  }

  /**
   * Adds a field.
   */
  private function addField(string $entity_type, string $bundle, string $name, string $type, array $settings): void {
    FieldStorageConfig::create([
      'entity_type' => $entity_type,
      'field_name' => $name,
      'type' => $type,
      'settings' => $settings,
      'cardinality' => -1,
    ])->save();
    FieldConfig::create(['entity_type' => $entity_type, 'bundle' => $bundle, 'field_name' => $name])->save();
  }

  /**
   * Paragraph IDs, revisions and texts stored on this site.
   */
  private function stored(): array {
    $database = $this->container->get('database');
    return [
      'paragraphs' => $database->query('SELECT id FROM {paragraphs_item} ORDER BY id')->fetchCol(),
      'revisions' => $database->query('SELECT revision_id FROM {paragraphs_item_revision} ORDER BY revision_id')->fetchCol(),
      'texts' => $database->query('SELECT field_text_value FROM {paragraph__field_text} ORDER BY entity_id')->fetchCol(),
    ];
  }

  /**
   * A failed save rolls back new, changed and nested paragraphs.
   */
  public function testFailedSaveKeepsParagraphs(): void {
    $inner = Paragraph::create([
      'type' => 'container',
      'field_children' => [Paragraph::create(['type' => 'text', 'field_text' => 'Nested'])],
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Sections',
      'uid' => 0,
      'field_sections' => [Paragraph::create(['type' => 'text', 'field_text' => 'First']), $inner],
    ]);
    $node->save();
    $snapshot = $this->snapshot($node);
    $this->forget($node);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $before = $this->stored();
    $this->assertSame(['First', 'Nested'], $before['texts']);

    // Every paragraph changes, one is added, and the save then fails after
    // the paragraphs were written.
    $failing = $snapshot;
    $failing['shared']['title'][0]['value'] = 'Explode';
    $sections = &$failing['shared']['field_sections'];
    $sections[0]['entity']['shared']['field_text'][0]['value'] = 'First, changed';
    $sections[1]['entity']['shared']['field_children'][0]['entity']['shared']['field_text'][0]['value'] = 'Nested, changed';
    $added = $sections[0];
    $added['entity']['uuid'] = $this->container->get('uuid')->generate();
    $added['entity']['shared']['field_text'][0]['value'] = 'Added';
    $sections[] = $added;
    unset($sections);
    $result = $this->apply($this->event($failing, Operation::Update, 2));
    $this->assertSame([422, 'save_failed'], [$result->httpStatus, $result->code]);

    $this->assertSame($before, $this->stored());
    $local = $this->local($snapshot['uuid']);
    $this->assertSame('Sections', $local->label());
    $this->assertCount(2, $local->get('field_sections'));
    $first = $local->get('field_sections')->entity;
    $this->assertInstanceOf(ParagraphInterface::class, $first);
    $this->assertSame('First', $first->get('field_text')->value);

    // The same changes without the failure go through.
    $working = $failing;
    $working['shared']['title'][0]['value'] = 'Sections';
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($working, Operation::Update, 3))->result);
    $this->assertSame(['Added', 'First, changed', 'Nested, changed'], $this->sorted($this->stored()['texts']));
  }

  /**
   * Sorts a list of strings.
   */
  private function sorted(array $values): array {
    sort($values);
    return $values;
  }

}
