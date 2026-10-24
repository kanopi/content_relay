<?php

declare(strict_types=1);

namespace Drupal\content_relay_test\Plugin\ContentRelay\Normalizer;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stands in for a third-party module's normalizer for its own entity type.
 *
 * It keeps the "name" field local and marks its snapshots, so a test can tell
 * it was chosen over the generic normalizer.
 */
#[ContentRelayNormalizer(
  id: 'content_relay_test',
  label: new TranslatableMarkup('Test entity'),
  entity_types: ['entity_test'],
)]
final class TestEntityNormalizer extends GenericContentEntityNormalizer {

  /**
   * {@inheritdoc}
   */
  public function getLocalFieldNames(ContentEntityTypeInterface $entity_type): array {
    return [...parent::getLocalFieldNames($entity_type), 'name'];
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(ContentEntityInterface $entity, NormalizationContext $context, EntitySerializer $serializer): array {
    $snapshot = parent::normalize($entity, $context, $serializer);
    $snapshot['metadata']['normalizer'] = $this->getPluginId();
    return $snapshot;
  }

}
