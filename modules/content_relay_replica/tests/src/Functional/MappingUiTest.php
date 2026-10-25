<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\content_relay\Traits\FileKeyTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the mapping UI: two-step form, preview, re-apply, and the as-is check.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MappingUiTest extends BrowserTestBase {

  use FileKeyTrait;

  private const CODE = 'K7QM-3XRP-V9TD';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_relay_replica', 'content_relay_test', 'node', 'text', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'news_item', 'name' => 'News item']);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_content', 'type' => 'text_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'news_item', 'field_name' => 'field_content'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_subtitle', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'news_item', 'field_name' => 'field_subtitle'])->save();
    $this->createFileKey('replica_signing');
    FakeOrigin::install($this->container->get('state'), self::CODE, ['node' => ['article', 'page']]);
    $this->container->get('content_relay_replica.subscription')->pair('https://' . FakeOrigin::HOST, self::CODE, ['bundles' => ['node' => ['article']]]);
  }

  /**
   * An origin article, made available by the fake origin's item endpoint.
   *
   * @return array
   *   The event.
   */
  private function originArticle(string $title, string $body): array {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'uid' => 0,
      'body' => ['value' => $body, 'format' => 'plain_text'],
    ]);
    $node->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($node)), TRUE);
    $node->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    $event = [
      'v' => SyncEvent::SCHEMA_VERSION,
      'event_id' => (new PhpUuid())->generate(),
      'sequence' => 1,
      'previous_sequence' => NULL,
      'operation' => 'update',
      'entity_type' => 'node',
      'bundle' => 'article',
      'entity_uuid' => $snapshot['uuid'],
      'source_revision' => 1,
      'revisionable' => TRUE,
      'source_changed' => 1700000000,
      'default_langcode' => $snapshot['default_langcode'],
      'langcodes' => array_keys($snapshot['translations']),
      'hash' => $this->container->get('content_relay.revision_hasher')->hash($snapshot)->toArray(),
      'contract_version' => 1,
      'created' => 1700000000,
      'dependencies' => [],
      'external_references' => [],
      'files' => [],
      'process' => NULL,
      'snapshot' => $snapshot,
    ];
    $state = $this->container->get('state');
    $config = $state->get(FakeOrigin::STATE);
    $config['items']['node:' . $snapshot['uuid']] = $event;
    $state->set(FakeOrigin::STATE, $config);
    return $event;
  }

  /**
   * A mapping made in the form, previewed, saved and re-applied.
   */
  public function testMappingForm(): void {
    $event = $this->originArticle('Mapped title', 'Mapped body');
    $this->drupalLogin($this->drupalCreateUser(['administer content relay mappings']));
    $this->drupalGet('admin/config/services/content-relay/replica/mappings');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('admin/config/services/content-relay/replica/mappings/add');
    $this->submitForm(['label' => 'Articles as news', 'source' => 'node.article', 'target' => 'node.news_item'], 'Continue');

    // Origin fields from the origin's schema, fields here as targets.
    $this->assertSession()->pageTextContains('body');
    $this->assertSession()->optionExists('fields[body][target]', 'field_content.value');
    $this->submitForm([
      'fields[title][target]' => 'title',
      'fields[body][target]' => 'field_content.value',
      'fields[body][source_property]' => 'value',
      'fields[uid][target]' => '_ignore',
      'extra[0][target]' => 'field_subtitle',
      'extra[0][process]' => "- plugin: default_value\n  configuration:\n    value: 'From the origin'",
    ], 'Save');
    $this->assertSession()->pageTextContains('Mapping Articles as news saved.');
    $mapping = ContentRelayMapping::load('node__article');
    $this->assertSame('news_item', $mapping->getTargetBundle());
    $this->assertSame(['uid'], $mapping->getIgnoredSourceFields());

    // Nothing synced yet, so nothing to re-apply.
    $this->assertSession()->pageTextContains('No items of this origin bundle have been received yet.');

    // Preview a real origin item: nothing saved.
    $this->drupalGet('admin/config/services/content-relay/replica/mappings/node__article');
    $this->submitForm(['uuid' => $event['entity_uuid']], 'Preview');
    $this->assertSession()->pageTextContains('Mapped title, as it would be saved here');
    $this->assertSession()->pageTextContains('Mapped body');
    $this->assertSession()->pageTextContains('From the origin');
    $this->assertNull($this->container->get('entity.repository')->loadEntityByUuid('node', $event['entity_uuid']));

    // Received under the mapping; then the mapping changes and is re-applied.
    $this->container->get('content_relay_replica.applier')->apply(SyncEvent::fromArray($event), FakeOrigin::SITE_ID);
    $this->assertSame('From the origin', $this->loadByUuid($event['entity_uuid'])->get('field_subtitle')->value);
    $this->drupalGet('admin/config/services/content-relay/replica/mappings/node__article');
    $this->submitForm(['extra[0][process]' => "- plugin: static_value\n  configuration:\n    value: 'Remapped'"], 'Save');
    $this->assertSession()->pageTextContains('Re-apply Articles as news to 1 synced item?');
    $this->submitForm([], 'Re-apply');
    $this->assertSession()->pageTextContains('1 item(s) re-applied, 0 failed');
    $this->assertSame('Remapped', $this->loadByUuid($event['entity_uuid'])->get('field_subtitle')->value);

    // Invalid transforms are refused with the reason.
    $this->drupalGet('admin/config/services/content-relay/replica/mappings/node__article');
    $this->submitForm(['extra[0][process]' => "- plugin: no_such_transform"], 'Save');
    $this->assertSession()->pageTextContains('Transform "no_such_transform" for "field_subtitle" does not exist.');
    $this->submitForm(['extra[0][process]' => 'not: [valid'], 'Save');
    $this->assertSession()->pageTextContains('The transforms are not valid YAML');
  }

  /**
   * Taking a bundle as it is is refused when it does not fit.
   */
  public function testAsIsRefusedWhenItDoesNotFit(): void {
    $schema = $this->container->get('content_relay.schema_describer')->describeMany(['node' => ['article', 'page']]);
    $schema['node']['page'] = $schema['node']['article'];
    $schema['node']['page']['label'] = 'Page';
    $schema['node']['page']['fields']['field_only_on_origin'] = [
      'type' => 'string',
      'cardinality' => 1,
      'translatable' => TRUE,
      'required' => FALSE,
      'target_type' => NULL,
      'properties' => ['value'],
    ];
    $state = $this->container->get('state');
    $state->set(FakeOrigin::STATE, ['schema' => $schema] + $state->get(FakeOrigin::STATE));
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);

    $this->drupalLogin($this->drupalCreateUser(['change content relay subscription']));
    $this->drupalGet('admin/config/services/content-relay/replica');
    $this->submitForm(['terms[bundles][node][page]' => TRUE], 'Propose changes');
    $this->assertSession()->pageTextContains('Page cannot be taken as it is: Field "field_only_on_origin" (string) is missing here. Add a mapping for it first.');
  }

  /**
   * Loads a node by UUID, fresh.
   */
  private function loadByUuid(string $uuid): Node {
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $node = $this->container->get('entity.repository')->loadEntityByUuid('node', $uuid);
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

}
