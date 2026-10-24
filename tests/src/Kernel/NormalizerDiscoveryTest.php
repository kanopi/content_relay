<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests normalizer selection and which entity types can be synced.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class NormalizerDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_relay', 'entity_test', 'key', 'system', 'user'];

  /**
   * Types without a UUID key are never offered; config entities neither.
   */
  public function testSyncableEntityTypes(): void {
    $types = $this->container->get('content_relay.syncable_entity_types');
    $ids = array_keys($types->getEntityTypes());
    $this->assertContains('entity_test', $ids);
    $this->assertContains('user', $ids);
    $this->assertNotContains('entity_test_no_uuid', $ids);
    $this->assertNotContains('user_role', $ids);
    $this->assertFalse($types->isSyncable('entity_test_no_uuid'));

    $this->expectException(\InvalidArgumentException::class);
    $this->container->get('plugin.manager.content_relay_normalizer')->getNormalizer('entity_test_no_uuid');
  }

  /**
   * The generic normalizer handles a custom entity type it knows nothing of.
   */
  public function testGenericNormalizerForCustomType(): void {
    $this->installEntitySchema('entity_test_mul');
    $this->installEntitySchema('user');
    $manager = $this->container->get('plugin.manager.content_relay_normalizer');
    $this->assertSame('generic', $manager->getNormalizer('entity_test_mul')->getPluginId());

    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test_mul')->create(['name' => 'Custom']);
    assert($entity instanceof ContentEntityInterface);
    $entity->save();
    $snapshot = $this->container->get('content_relay.entity_serializer')->normalize($entity);
    $this->assertSame('entity_test_mul', $snapshot['entity_type']);
    // Without the language module nothing is translatable, so it is shared.
    $this->assertSame([['value' => 'Custom']], $snapshot['shared']['name']);
  }

  /**
   * A third-party plugin replaces the generic one for its entity type.
   */
  public function testThirdPartyNormalizerOverrides(): void {
    $this->container->get('module_installer')->install(['content_relay_test']);
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $manager = $this->container->get('plugin.manager.content_relay_normalizer');
    $this->assertSame('content_relay_test', $manager->getNormalizer('entity_test')->getPluginId());
    $this->assertSame('user', $manager->getNormalizer('user')->getPluginId());
    $this->assertSame('generic', $manager->getNormalizer('entity_test_mul')->getPluginId());

    $entity = EntityTest::create(['name' => 'Local only']);
    $entity->save();
    $snapshot = $this->container->get('content_relay.entity_serializer')->normalize($entity);
    $this->assertSame('content_relay_test', $snapshot['metadata']['normalizer']);
    $this->assertStringNotContainsString('Local only', json_encode($snapshot));
  }

}
