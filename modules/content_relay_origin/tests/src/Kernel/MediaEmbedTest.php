<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a media item embedded in formatted text.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class MediaEmbedTest extends OriginTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'file', 'image', 'media', 'text'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['file', 'image', 'media']);
    $this->createMediaType('file', ['id' => 'document']);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body'])->save();
    $this->config('content_relay_origin.settings')->set('bundles', [
      'node' => ['article', 'page'],
      'media' => ['document'],
    ])->save();
    $this->pairAndAcknowledge(NULL, ['node' => ['article'], 'media' => ['document']]);
  }

  /**
   * The media item and its file go before the text embedding it.
   */
  public function testEmbeddedMediaIsSentFirst(): void {
    $directory = 'public://';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents('public://annual.pdf', 'pdf bytes');
    $file = File::create(['uri' => 'public://annual.pdf', 'filename' => 'annual.pdf', 'status' => 1]);
    $file->save();
    $media = Media::create(['bundle' => 'document', 'name' => 'Annual report', 'field_media_file' => $file]);
    $media->save();
    $embed = sprintf('<p>See</p><drupal-media data-entity-type="media" data-entity-uuid="%s" data-view-mode="default"></drupal-media>', $media->uuid());
    $node = Node::create([
      'type' => 'article',
      'title' => 'Embedding',
      'uid' => 0,
      'body' => ['value' => $embed, 'format' => 'plain_text'],
    ]);
    $node->save();
    $this->deliver();

    $order = array_map(fn (array $event) => $event['entity_type'] . ':' . $event['entity_uuid'], $this->replica->events);
    $position = array_flip($order);
    $this->assertArrayHasKey('file:' . $file->uuid(), $position);
    $this->assertArrayHasKey('media:' . $media->uuid(), $position);
    $this->assertLessThan($position['media:' . $media->uuid()], $position['file:' . $file->uuid()]);
    $this->assertLessThan($position['node:' . $node->uuid()], $position['media:' . $media->uuid()]);

    $node_event = $this->replica->events[$position['node:' . $node->uuid()]];
    $this->assertContains(['entity_type' => 'media', 'uuid' => $media->uuid()], $node_event['dependencies']);
    $this->assertStringContainsString($media->uuid(), $node_event['snapshot']['shared']['body'][0]['value'], 'The embed keeps naming the item by UUID.');
    $media_event = $this->replica->events[$position['media:' . $media->uuid()]];
    $this->assertContains(['entity_type' => 'file', 'uuid' => $file->uuid()], $media_event['dependencies']);
  }

}
