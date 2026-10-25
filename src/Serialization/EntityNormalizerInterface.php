<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;

/**
 * Turns entities of a type into snapshots and back.
 *
 * Implementations must keep two promises, because hashes depend on them:
 *
 * - Deterministic: the same entity always produces the same snapshot, on any
 *   site, regardless of load order or how values were set in memory.
 * - Round trip: denormalizing a snapshot, saving the entity and normalizing it
 *   again produces the same snapshot, apart from the metadata section.
 *
 * Extend \Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer
 * rather than implementing this from scratch; most special cases are one or
 * two overridden methods.
 *
 * @see \Drupal\content_relay\Serialization\Snapshot
 */
interface EntityNormalizerInterface extends PluginInspectionInterface {

  /**
   * Whether this plugin can handle an entity type.
   */
  public function applies(ContentEntityTypeInterface $entity_type): bool;

  /**
   * Whether entities of this type travel inside their parent's snapshot.
   *
   * Embedded entities (paragraphs) have no life of their own: they are sent,
   * hashed and deleted as part of the entity that holds them, never as events
   * of their own.
   */
  public function isEmbedded(ContentEntityTypeInterface $entity_type): bool;

  /**
   * Builds the snapshot of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, in any translation; all translations are normalized.
   * @param \Drupal\content_relay\Serialization\NormalizationContext $context
   *   Options and collected references.
   * @param \Drupal\content_relay\Serialization\EntitySerializer $serializer
   *   The serializer, for embedded entities.
   *
   * @return array
   *   The snapshot.
   */
  public function normalize(ContentEntityInterface $entity, NormalizationContext $context, EntitySerializer $serializer): array;

  /**
   * Applies a snapshot to a new or existing entity, without saving it.
   *
   * @param array $snapshot
   *   The snapshot, already validated.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The local entity with the snapshot's UUID, or NULL to create one.
   * @param \Drupal\content_relay\Serialization\DenormalizationContext $context
   *   Options and collected problems.
   * @param \Drupal\content_relay\Serialization\EntitySerializer $serializer
   *   The serializer, for embedded entities.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity in its default translation, unsaved.
   */
  public function denormalize(array $snapshot, ?ContentEntityInterface $entity, DenormalizationContext $context, EntitySerializer $serializer): ContentEntityInterface;

  /**
   * Fields that are always local to a site and never leave it.
   *
   * @return string[]
   *   Field names.
   */
  public function getLocalFieldNames(ContentEntityTypeInterface $entity_type): array;

  /**
   * The fields synced for a bundle, keyed by name, in a stable order.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Field definitions.
   */
  public function getSyncedFieldDefinitions(ContentEntityTypeInterface $entity_type, string $bundle): array;

}
