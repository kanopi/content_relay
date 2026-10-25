<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\Normalizer;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerManager;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * File entities: their content by checksum, their location as metadata.
 *
 * The URI is where a site keeps the file, not what the file is, so it travels
 * in metadata and moving a file does not change its hash; the checksum and
 * size of its content are hashed instead, under CONTENT. The binary itself is
 * not in the snapshot: the receiver fetches it separately.
 *
 * A new file whose URI is taken on the receiving site, by another file
 * entity or by a file on disk that no entity owns, is given a free name next
 * to it, so sync never overwrites a file it did not create.
 */
#[ContentRelayNormalizer(
  id: 'file',
  label: new TranslatableMarkup('File'),
  entity_types: ['file'],
)]
class File extends GenericContentEntityNormalizer {

  public const CONTENT = 'content_relay_content';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    FieldNormalizerManager $field_normalizers,
    EntityFieldManagerInterface $entity_field_manager,
    protected FileChecksums $checksums,
    protected FileSystemInterface $fileSystem,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $language_manager, $field_normalizers, $entity_field_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('plugin.manager.content_relay_field_normalizer'),
      $container->get('entity_field.manager'),
      $container->get('content_relay.file_checksums'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(ContentEntityInterface $entity, NormalizationContext $context, EntitySerializer $serializer): array {
    $snapshot = parent::normalize($entity, $context, $serializer);
    assert($entity instanceof FileInterface);
    $uri = $entity->getFileUri();
    unset($snapshot['shared']['uri']);
    $snapshot['metadata']['file'] = ['uri' => $uri];
    $checksum = $this->checksums->forUri($uri);
    if ($checksum !== NULL) {
      $snapshot['shared'][self::CONTENT] = [['sha256' => $checksum['sha256'], 'size' => $checksum['size']]];
    }
    return $snapshot;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize(array $snapshot, ?ContentEntityInterface $entity, DenormalizationContext $context, EntitySerializer $serializer): ContentEntityInterface {
    unset($snapshot['shared'][self::CONTENT]);
    $uri = $snapshot['metadata']['file']['uri'] ?? NULL;
    if (!is_string($uri) || $uri === '') {
      throw new \InvalidArgumentException(sprintf('File %s arrived without a URI.', $snapshot['uuid']));
    }
    if ($entity instanceof FileInterface && $entity->getFileUri() !== $uri && $this->isTaken($uri, $entity)) {
      // The origin moved the file to a place taken here: stay put.
      $uri = $entity->getFileUri();
    }
    elseif (!$entity instanceof FileInterface && $this->isTaken($uri, NULL)) {
      $uri = $this->fileSystem->getDestinationFilename($uri, FileExists::Rename) ?: $uri;
    }
    $snapshot['shared']['uri'] = [['value' => $uri]];
    return parent::denormalize($snapshot, $entity, $context, $serializer);
  }

  /**
   * Whether a URI is used by another file entity or an unowned file on disk.
   */
  protected function isTaken(string $uri, ?FileInterface $own): bool {
    $owners = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    foreach ($owners as $owner) {
      if ($own === NULL || $owner->id() !== $own->id()) {
        return TRUE;
      }
    }
    return $owners === [] && file_exists($uri);
  }

}
