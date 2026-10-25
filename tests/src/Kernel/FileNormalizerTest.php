<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer\FileReference;
use Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer\FormattedText;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\File as FileNormalizer;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests files in snapshots: file entities, file fields and links in text.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class FileNormalizerTest extends KernelTestBase {

  use ImageFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_translation',
    'field',
    'file',
    'filter',
    'image',
    'key',
    'language',
    'node',
    'system',
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
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('content_relay', [FileChecksums::TABLE]);
    $this->installConfig(['language', 'node', 'filter', 'content_relay']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')->setLanguageAlterable(TRUE)->setDefaultLangcode('en')->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_with_summary'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body', 'translatable' => TRUE])->save();
    $this->createImageField('field_image', 'node', 'article');
    ImageStyle::create(['name' => 'thumbnail', 'label' => 'Thumbnail'])->save();
    $this->config('content_relay.settings')->set('files.own_domains', ['cdn.origin.example'])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->serializer = $this->container->get('content_relay.entity_serializer');
    $this->hasher = $this->container->get('content_relay.revision_hasher');
  }

  /**
   * Stores a file and its entity.
   */
  private function file(string $uri, string $content): FileInterface {
    $directory = $this->container->get('file_system')->dirname($uri);
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($uri, $content);
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * Overwrites a file's content, as replacing it in place would.
   */
  private function rewrite(FileInterface $file, string $content): void {
    file_put_contents($file->getFileUri(), $content);
    // A different modification time, as a real later write would have.
    touch($file->getFileUri(), time() + 10);
  }

  /**
   * An article with a body.
   */
  private function article(string $body, array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'article',
      'title' => 'Linked',
      'uid' => 0,
      'langcode' => 'en',
      'body' => ['value' => $body, 'format' => 'plain_text'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * File entities hash their content, not their location.
   */
  public function testFileEntity(): void {
    $file = $this->file('public://docs/report.pdf', 'version one');
    $snapshot = $this->serializer->normalize($file);
    $this->assertArrayNotHasKey('uri', $snapshot['shared']);
    $this->assertSame('public://docs/report.pdf', $snapshot['metadata']['file']['uri']);
    $this->assertSame([['sha256' => hash('sha256', 'version one'), 'size' => 11]], $snapshot['shared'][FileNormalizer::CONTENT]);
    $hash = $this->hasher->hash($snapshot)->entity;

    // Moved: same hash.
    $directory = 'public://moved';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    rename('public://docs/report.pdf', 'public://moved/report.pdf');
    $file->setFileUri('public://moved/report.pdf');
    $file->save();
    $this->assertSame($hash, $this->hasher->hash($this->serializer->normalize($file))->entity);

    // New content: a new hash.
    $this->rewrite($file, 'version two');
    $this->assertNotSame($hash, $this->hasher->hash($this->serializer->normalize($file))->entity);
  }

  /**
   * A new file never overwrites a local one at the same URI.
   */
  public function testUriCollisionIsAvoided(): void {
    $file = $this->file('public://shared.txt', 'origin');
    $snapshot = json_decode(json_encode($this->serializer->normalize($file)), TRUE);
    $file->delete();
    file_put_contents('public://shared.txt', 'local file nobody synced');

    $copy = $this->serializer->denormalize($snapshot);
    $this->assertInstanceOf(FileInterface::class, $copy);
    $this->assertSame('public://shared_0.txt', $copy->getFileUri());
    $this->assertSame($snapshot['uuid'], $copy->uuid());
  }

  /**
   * File and image fields carry the file's checksum.
   */
  public function testFileFieldCarriesChecksum(): void {
    $image = $this->file('public://photo.png', 'png bytes');
    $node = $this->article('', ['field_image' => ['target_id' => $image->id(), 'alt' => 'A photo']]);
    $snapshot = $this->serializer->normalize($node);
    $item = $snapshot['shared']['field_image'][0] ?? $snapshot['translations']['en']['field_image'][0];
    $this->assertSame($image->uuid(), $item['target_uuid']);
    $this->assertSame(hash('sha256', 'png bytes'), $item[FileReference::CHECKSUM]);
    $this->assertSame('A photo', $item['alt']);
    $hash = $this->hasher->hash($snapshot)->entity;

    $this->rewrite($image, 'other png bytes');
    $this->container->get('content_relay.file_checksums')->forget($image->getFileUri());
    $this->assertNotSame($hash, $this->hasher->hash($this->serializer->normalize($node))->entity);
  }

  /**
   * Every kind of file link in text becomes a token; others are left alone.
   */
  public function testLinksInText(): void {
    $inline = $this->file('public://inline/photo.png', 'inline bytes');
    $pdf = $this->file('public://report.pdf', 'pdf bytes');
    $private = $this->file('private://secret.pdf', 'secret bytes');
    $other = Node::create(['type' => 'article', 'title' => 'Embedded', 'uid' => 0]);
    $other->save();

    $body = implode("\n", [
      '<p><img data-entity-type="file" data-entity-uuid="' . $inline->uuid() . '" src="/' . $this->publicPath() . '/inline/photo.png" alt="x"></p>',
      '<p><a href="/' . $this->publicPath() . '/report.pdf">Report</a></p>',
      '<p><a href="/system/files/secret.pdf">Secret</a></p>',
      '<p><img src="/' . $this->publicPath() . '/styles/thumbnail/public/inline/photo.png?itok=abc"></p>',
      '<p><a href="https://cdn.origin.example/' . $this->publicPath() . '/report.pdf">CDN</a></p>',
      '<p><a href="https://elsewhere.example/' . $this->publicPath() . '/report.pdf">Elsewhere</a></p>',
      '<p><a href="/' . $this->publicPath() . '/missing.pdf">Missing</a></p>',
      '<drupal-entity data-entity-type="node" data-entity-uuid="' . $other->uuid() . '"></drupal-entity>',
    ]);
    $node = $this->article($body);
    $context = new NormalizationContext();
    $snapshot = $this->serializer->normalize($node, $context);
    $item = $snapshot['translations']['en']['body'][0];
    $value = $item['value'];

    $this->assertStringContainsString('src="{cr-file:' . $inline->uuid() . '}"', $value);
    $this->assertStringContainsString('href="{cr-file:' . $pdf->uuid() . '}">Report', $value);
    $this->assertStringContainsString('href="{cr-file:' . $private->uuid() . '}">Secret', $value);
    $this->assertStringContainsString('src="{cr-file:' . $inline->uuid() . ':thumbnail}"', $value);
    $this->assertStringContainsString('href="{cr-file:' . $pdf->uuid() . '}">CDN', $value);
    $this->assertStringContainsString('href="https://elsewhere.example/', $value);
    $this->assertStringContainsString('missing.pdf">Missing', $value);

    $uuids = array_column($item[FormattedText::FILES], 'uuid');
    $expected = [$inline->uuid(), $pdf->uuid(), $private->uuid()];
    sort($expected);
    $this->assertSame($expected, $uuids);
    foreach (['file:' . $inline->uuid(), 'file:' . $pdf->uuid(), 'file:' . $private->uuid(), 'node:' . $other->uuid()] as $key) {
      $this->assertArrayHasKey($key, $context->references);
    }
    $this->assertSame([['field' => 'body', 'entity_type' => 'file', 'id' => '/' . $this->publicPath() . '/missing.pdf']], $context->unresolved);

    // Replacing a linked file's content changes the item's hash.
    $hash = $this->hasher->hash($snapshot)->entity;
    $this->rewrite($pdf, 'new pdf bytes');
    $this->assertNotSame($hash, $this->hasher->hash($this->serializer->normalize($node))->entity);
  }

  /**
   * Tokens become local URLs, and hash back to the same tokens.
   */
  public function testRoundTripThroughLocalUrls(): void {
    $pdf = $this->file('public://report.pdf', 'pdf bytes');
    $inline = $this->file('public://inline/photo.png', 'inline bytes');
    $node = $this->article('<a href="/' . $this->publicPath() . '/report.pdf">Report</a> <img src="/' . $this->publicPath() . '/styles/thumbnail/public/inline/photo.png">');
    $snapshot = json_decode(json_encode($this->serializer->normalize($node)), TRUE);
    $hash = $this->hasher->hash($snapshot);
    $node->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();

    $copy = $this->serializer->denormalize($snapshot);
    $copy->save();
    $saved = (string) $copy->get('body')->value;
    $this->assertStringContainsString('href="/' . $this->publicPath() . '/report.pdf"', $saved);
    $this->assertStringContainsString('/styles/thumbnail/public/inline/photo.png?itok=', $saved);
    $this->assertStringNotContainsString('{cr-file:', $saved);
    $this->assertTrue($this->hasher->matches($this->serializer->normalize($copy), $hash));
    $this->assertNotNull($pdf->id());
    $this->assertNotNull($inline->id());
  }

  /**
   * Each translation gets its own files.
   */
  public function testTranslationsHaveTheirOwnFiles(): void {
    $english = $this->file('public://en.pdf', 'english');
    $french = $this->file('public://fr.pdf', 'french');
    $node = $this->article('<a href="/' . $this->publicPath() . '/en.pdf">EN</a>');
    $node->addTranslation('fr', [
      'title' => 'Lié',
      'body' => ['value' => '<a href="/' . $this->publicPath() . '/fr.pdf">FR</a>', 'format' => 'plain_text'],
    ])->save();
    $snapshot = $this->serializer->normalize($node);
    $this->assertSame([$english->uuid()], array_column($snapshot['translations']['en']['body'][0][FormattedText::FILES], 'uuid'));
    $this->assertSame([$french->uuid()], array_column($snapshot['translations']['fr']['body'][0][FormattedText::FILES], 'uuid'));
  }

  /**
   * The public files path, without slashes.
   */
  private function publicPath(): string {
    return trim(PublicStream::basePath(), '/');
  }

}
