<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests comparing the origin's schema with this site's, and noticing drift.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class SchemaTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    [, $material] = KeyPair::generate(KeyPurpose::Signing);
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY=' . $material);
    Key::create([
      'id' => 'replica_signing',
      'label' => 'Replica signing',
      'key_type' => 'content_relay_signing',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_REPLICA_TEST_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();
    $origin_key = FakeOrigin::install($this->container->get('state'), 'UNUSED', [
      'node' => ['article'],
      'taxonomy_term' => ['tags'],
    ]);
    $this->setOrigin(['replica' => $this->container->get('content_relay.site_identity')->getSiteId()]);
    $this->container->get('content_relay_replica.origin')->set([
      'site_id' => FakeOrigin::SITE_ID,
      'url' => 'https://' . FakeOrigin::HOST,
      'signing_keys' => [
        $origin_key->id() => ['public_key' => base64_encode($origin_key->publicKey), 'status' => 'primary'],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY');
    parent::tearDown();
  }

  /**
   * Changes the fake origin's state.
   */
  private function setOrigin(array $values): void {
    $state = $this->container->get('state');
    $state->set(FakeOrigin::STATE, $values + $state->get(FakeOrigin::STATE));
  }

  /**
   * This site's article, as the origin would describe it.
   */
  private function article(): array {
    return $this->container->get('content_relay.schema_describer')->describe('node', 'article');
  }

  /**
   * The same bundle fits; differences are named.
   */
  public function testCompatibility(): void {
    $checker = $this->container->get('content_relay_replica.compatibility_checker');
    $this->assertSame(['problems' => [], 'warnings' => []], $checker->check('node', 'article', $this->article()));

    $origin = $this->article();
    $origin['fields']['field_extra'] = [
      'type' => 'string',
      'cardinality' => 1,
      'translatable' => TRUE,
      'required' => FALSE,
      'target_type' => NULL,
      'properties' => ['value'],
    ];
    $origin['fields']['title']['type'] = 'text';
    $origin['fields']['field_tags']['cardinality'] = 1;
    $origin['fields']['status']['translatable'] = !$origin['fields']['status']['translatable'];
    unset($origin['fields']['sticky']);
    $result = $checker->check('node', 'article', $origin);
    $this->assertContains('Field "field_extra" (string) is missing here.', $result['problems']);
    $this->assertContains('Field "title" is text on the origin and string here.', $result['problems']);
    $this->assertContains('Field "field_tags" holds one value on the origin and unlimited values here.', $result['problems']);
    $this->assertCount(3, $result['problems']);
    $this->assertCount(2, $result['warnings']);

    $this->assertSame(['This site has no node bundle "press_release".'], $checker->check('node', 'press_release', $origin)['problems']);
  }

  /**
   * A change in the origin's schema is noticed and reported.
   */
  public function testWatcher(): void {
    $watcher = $this->container->get('content_relay_replica.schema_watcher');
    $results = $watcher->check();
    $this->assertSame([], $results['node.article']['problems']);
    $this->assertFalse($results['node.article']['changed']);
    $this->assertSame([], $watcher->problems());

    // The origin adds a field.
    $schema = $this->container->get('content_relay.schema_describer')->describeMany([
      'node' => ['article'],
      'taxonomy_term' => ['tags'],
    ]);
    $schema['node']['article']['fields']['field_new'] = [
      'type' => 'string',
      'cardinality' => 1,
      'translatable' => TRUE,
      'required' => FALSE,
      'target_type' => NULL,
      'properties' => ['value'],
    ];
    $this->setOrigin(['schema' => $schema]);
    $results = $watcher->check();
    $this->assertTrue($results['node.article']['changed']);
    $this->assertSame(['node.article' => ['Field "field_new" (string) is missing here.']], $watcher->problems());
    $requirements = content_relay_replica_requirements('runtime');
    $this->assertSame(REQUIREMENT_WARNING, $requirements['content_relay_replica_schema']['severity']);

    // Mapped, the question is whether the mapping knows the field.
    ContentRelayMapping::create([
      'id' => 'node__article',
      'label' => 'Articles',
      'source_entity_type' => 'node',
      'source_bundle' => 'article',
      'target_entity_type' => 'node',
      'target_bundle' => 'article',
      'fields' => [['source' => 'title', 'target' => 'title', 'process' => []]],
      'ignored_source_fields' => array_diff(array_keys($schema['node']['article']['fields']), ['title', 'field_new']),
    ])->save();
    $results = $watcher->check();
    $this->assertSame('mapped', $results['node.article']['mode']);
    $this->assertSame(['Origin fields neither mapped nor ignored by mapping node__article: field_new.'], $results['node.article']['problems']);
  }

}
