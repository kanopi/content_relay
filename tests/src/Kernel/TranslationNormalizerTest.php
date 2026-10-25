<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests translations in snapshots and per-translation hashes.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class TranslationNormalizerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_translation',
    'key',
    'language',
    'node',
    'system',
    'text',
    'field',
    'filter',
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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['language', 'node', 'filter']);
    foreach (['fr', 'zh-hans', 'ar'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'page')->setLanguageAlterable(TRUE)->setDefaultLangcode('en')->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->serializer = $this->container->get('content_relay.entity_serializer');
    $this->hasher = $this->container->get('content_relay.revision_hasher');
  }

  /**
   * A page in English, French and Simplified Chinese.
   */
  private function createPage(): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => 'Hello', 'langcode' => 'en', 'uid' => 0]);
    $node->addTranslation('fr', ['title' => 'Bonjour']);
    $node->addTranslation('zh-hans', ['title' => '你好']);
    $node->save();
    return $node;
  }

  /**
   * Every translation is in one snapshot, with its own hash.
   */
  public function testTranslationsShareOneSnapshot(): void {
    $node = $this->createPage();
    $snapshot = $this->serializer->normalize($node->getTranslation('fr'));
    $this->assertSame('en', $snapshot['default_langcode'], 'The default translation leads regardless of which was passed.');
    $this->assertSame(['en', 'fr', 'zh-hans'], array_keys($snapshot['translations']));
    $this->assertSame([['value' => 'Bonjour']], $snapshot['translations']['fr']['title']);
    // Node base fields are translatable, so each translation has its own.
    $this->assertSame([['target_type' => 'user', 'target_uuid' => 'anonymous']], $snapshot['translations']['fr']['uid']);

    $hashes = $this->hasher->hash($snapshot);
    $this->assertSame(['en', 'fr', 'zh-hans'], array_keys($hashes->translations));
  }

  /**
   * Updating one translation changes that translation's hash only.
   */
  public function testOneTranslationChanges(): void {
    $node = $this->createPage();
    $before = $this->hasher->hash($this->serializer->normalize($node));
    $node->getTranslation('fr')->setTitle('Salut')->save();
    $after = $this->hasher->hash($this->serializer->normalize(Node::load($node->id())));
    $this->assertSame(['fr'], $before->differingTranslations($after));
  }

  /**
   * A receiver that filters languages gets only those, not even the default.
   */
  public function testLanguageFilter(): void {
    $node = $this->createPage();
    $snapshot = $this->serializer->normalize($node, new NormalizationContext(['zh-hans']));
    $this->assertSame(['zh-hans'], array_keys($snapshot['translations']));
    // The default is still named, so the receiver knows it is not its own.
    $this->assertSame('en', $snapshot['default_langcode']);
    $this->assertStringNotContainsString('Bonjour', json_encode($snapshot));
    $this->assertStringNotContainsString('Hello', json_encode($snapshot['translations']));

    // A per-translation hash does not depend on what else was sent.
    $full = $this->hasher->hash($this->serializer->normalize($node));
    $filtered = $this->hasher->hash($snapshot);
    $this->assertSame($full->translations['zh-hans'], $filtered->translations['zh-hans']);
  }

  /**
   * Translations are added, updated and removed on the receiving entity.
   */
  public function testTranslationLifecycleOnApply(): void {
    $node = $this->createPage();
    $snapshot = $this->serializer->normalize($node);

    // The origin deleted French, updated Chinese and added Arabic.
    unset($snapshot['translations']['fr']);
    $snapshot['translations']['zh-hans']['title'] = [['value' => '你好，世界']];
    $snapshot['translations']['ar'] = $snapshot['translations']['en'];
    $snapshot['translations']['ar']['title'] = [['value' => 'مرحبا']];
    $expected = $this->hasher->hash($snapshot);

    $entity = $this->serializer->denormalize($snapshot);
    $entity->save();
    $saved = Node::load($node->id());
    $this->assertFalse($saved->hasTranslation('fr'));
    $this->assertSame('你好，世界', $saved->getTranslation('zh-hans')->label());
    $this->assertSame('مرحبا', $saved->getTranslation('ar')->label());
    $this->assertEquals($expected, $this->hasher->hash($this->serializer->normalize($saved)));
  }

  /**
   * Translations this sync does not manage are left alone.
   */
  public function testUnmanagedTranslationsAreKept(): void {
    $node = $this->createPage();
    $snapshot = $this->serializer->normalize($node, new NormalizationContext(['fr']));
    $entity = $this->serializer->denormalize($snapshot, new DenormalizationContext(managedLangcodes: ['en', 'fr']));
    $this->assertTrue($entity->hasTranslation('zh-hans'), 'A translation in a language this receiver does not get is kept.');
  }

  /**
   * A translation in a language that does not exist here is skipped.
   */
  public function testUnknownLanguageIsSkipped(): void {
    $snapshot = $this->serializer->normalize($this->createPage());
    $snapshot['translations']['de'] = ['title' => [['value' => 'Hallo']]];
    $context = new DenormalizationContext();
    $entity = $this->serializer->denormalize($snapshot, $context);
    $this->assertFalse($entity->hasTranslation('de'));
    $this->assertArrayHasKey('de', $context->skippedTranslations);
  }

}
