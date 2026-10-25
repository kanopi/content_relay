<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * File and image fields: references that also carry the file's checksum.
 *
 * The checksum puts the file's content into the hash of the item using it,
 * so replacing a file's content marks the item out of sync even though the
 * reference itself did not change. A file that is missing from storage
 * carries no checksum.
 */
#[ContentRelayFieldNormalizer(
  id: 'file_reference',
  label: new TranslatableMarkup('File reference'),
  weight: 5,
)]
class FileReference extends EntityReference {

  public const CHECKSUM = 'sha256';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    protected FileChecksums $checksums,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('content_relay.file_checksums'));
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return self::itemIs($definition, 'Drupal\file\Plugin\Field\FieldType\FileItem');
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    $normalized = parent::normalizeItem($item, $values, $context, $serializer);
    if ($normalized === NULL || ($normalized[Snapshot::TARGET_TYPE] ?? NULL) !== 'file' || !isset($values['target_id'])) {
      return $normalized;
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $this->entityTypeManager->getStorage('file')->load($values['target_id']);
    $checksum = $file === NULL ? NULL : $this->checksums->forUri($file->getFileUri());
    if ($checksum !== NULL) {
      $normalized[self::CHECKSUM] = $checksum['sha256'];
    }
    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    unset($item[self::CHECKSUM]);
    return parent::denormalizeItem($item, $definition, $context, $serializer);
  }

}
