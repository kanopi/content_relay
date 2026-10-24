<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Embeds composite entities in the entity that holds them.
 *
 * Applies to Entity Reference Revisions fields whose target is composite
 * (declares the parent keys): paragraphs, and anything else built that way.
 * The target's whole snapshot travels inside the parent item, so a tree of
 * any depth is sent, hashed and applied as one unit. An Entity Reference
 * Revisions field pointing at an ordinary entity is left to the plain
 * reference normalizer.
 *
 * Entity Reference Revisions is not a dependency: its classes are named, not
 * imported, and nothing here runs unless such a field exists.
 */
#[ContentRelayFieldNormalizer(
  id: 'embedded_revision',
  label: new TranslatableMarkup('Embedded entity'),
  weight: 10,
)]
class EmbeddedRevision extends EntityReference {

  public const ITEM_CLASS = 'Drupal\entity_reference_revisions\Plugin\Field\FieldType\EntityReferenceRevisionsItem';

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    if (!self::itemIs($definition, self::ITEM_CLASS)) {
      return FALSE;
    }
    $target = $this->entityTypeManager->getDefinition((string) $definition->getSetting('target_type'), FALSE);
    return $target instanceof ContentEntityTypeInterface && GenericContentEntityNormalizer::isComposite($target);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    $target = $item->get('entity')->getValue();
    unset($values['target_id'], $values['target_revision_id']);
    if (!$target instanceof ContentEntityInterface) {
      $context->unresolved[] = [
        'field' => $item->getFieldDefinition()->getName(),
        'entity_type' => (string) $item->getFieldDefinition()->getSetting('target_type'),
        'id' => $item->target_id ?? '',
      ];
      return NULL;
    }
    $values[Snapshot::EMBEDDED] = $serializer->normalize($target, $context);
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    if (!isset($item[Snapshot::EMBEDDED]) || !is_array($item[Snapshot::EMBEDDED])) {
      return NULL;
    }
    // The embedded entity's own fields are located under this item.
    $prefix = $context->prefix;
    $location = $context->location;
    $delta = $context->delta;
    $context->prefix = [...$location, $delta, Snapshot::EMBEDDED];
    try {
      $child = $serializer->denormalize($item[Snapshot::EMBEDDED], $context);
    }
    finally {
      $context->prefix = $prefix;
      $context->location = $location;
      $context->delta = $delta;
    }
    // Entity Reference Revisions saves a child with its parent only when it
    // is new or says it needs saving.
    if (method_exists($child, 'setNeedsSave')) {
      $child->setNeedsSave(TRUE);
    }
    unset($item[Snapshot::EMBEDDED], $item[Snapshot::TARGET_TYPE]);
    return ['entity' => $child] + $item;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedIds(FieldItemInterface $item): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    return [];
  }

}
