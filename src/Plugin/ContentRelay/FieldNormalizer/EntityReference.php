<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerBase;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity references of any kind, on any entity type.
 *
 * Covers every field type built on core's entity reference item: entity
 * reference, file, image, comment's host reference, and contrib types such as
 * dynamic entity reference (whose target type is per item rather than per
 * field). Content targets travel as UUIDs; configuration targets keep their
 * machine name, which is the same on every site.
 *
 * A target ID of 0 means "none" by convention (a taxonomy term's root parent)
 * and is left out, except for user 0, the anonymous user, which exists on
 * every site under a different UUID and so travels as a marker.
 */
#[ContentRelayFieldNormalizer(
  id: 'entity_reference',
  label: new TranslatableMarkup('Entity reference'),
)]
class EntityReference extends FieldNormalizerBase implements ContainerFactoryPluginInterface {

  public const ANONYMOUS = 'anonymous';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return self::itemIs($definition, EntityReferenceItem::class);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    $target_type = $this->targetType($item->getFieldDefinition(), $values);
    $field = $item->getFieldDefinition()->getName();
    $id = $values['target_id'] ?? NULL;
    unset($values['target_id'], $values['target_revision_id']);
    if ($id === NULL || $target_type === '') {
      // An unsaved target: nothing on the other site can match it.
      return NULL;
    }
    $values[Snapshot::TARGET_TYPE] = $target_type;

    if ($this->isConfigTarget($target_type)) {
      $values['target_id'] = (string) $id;
      return $values;
    }
    if ($target_type === 'user' && (string) $id === '0') {
      $values[Snapshot::TARGET_UUID] = self::ANONYMOUS;
      return $values;
    }
    if ((string) $id === '0') {
      return NULL;
    }
    if (!$this->isUuidTarget($target_type)) {
      $context->unresolved[] = ['field' => $field, 'entity_type' => $target_type, 'id' => $id];
      return NULL;
    }
    $uuid = $serializer->getUuidResolver()->toUuid($target_type, $id);
    if ($uuid === NULL) {
      $context->unresolved[] = ['field' => $field, 'entity_type' => $target_type, 'id' => $id];
      return NULL;
    }
    $values[Snapshot::TARGET_UUID] = $uuid;
    $context->addReference($target_type, $uuid);
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    $target_type = $item[Snapshot::TARGET_TYPE] ?? $this->targetType($definition, []);
    $uuid = $item[Snapshot::TARGET_UUID] ?? NULL;
    $portable = $item;
    unset($item[Snapshot::TARGET_UUID]);
    // Keep target_type only where the field stores it per item.
    if (!$this->storesTargetType($definition)) {
      unset($item[Snapshot::TARGET_TYPE]);
    }

    if ($this->isConfigTarget($target_type)) {
      return isset($item['target_id']) ? $item : NULL;
    }
    if ($target_type === 'user' && $uuid === self::ANONYMOUS) {
      return ['target_id' => 0] + $item;
    }
    if (!is_string($uuid) || !$this->entityTypeManager->hasDefinition($target_type)) {
      return NULL;
    }
    $id = $serializer->getUuidResolver()->toId($target_type, $uuid);
    if ($id !== NULL) {
      return ['target_id' => $id] + $item;
    }
    if (!$context->isExternal($target_type, $uuid)) {
      $context->addMissingReference($target_type, $uuid, $definition->getName());
      return NULL;
    }

    // The target is outside the subscription and will never arrive. Local
    // policy decides what to use instead, and the substitution is recorded
    // so the copy is still verified against what it should be.
    $fallback = $context->externalFallback ? ($context->externalFallback)($target_type, $uuid) : NULL;
    if ($fallback === NULL) {
      $context->substitute(NULL);
      return NULL;
    }
    $fallback_uuid = (string) $fallback === '0' && $target_type === 'user' ? self::ANONYMOUS : $serializer->getUuidResolver()->toUuid($target_type, $fallback);
    $context->substitute([Snapshot::TARGET_UUID => $fallback_uuid] + $portable);
    return ['target_id' => $fallback] + $item;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedIds(FieldItemInterface $item): array {
    $target_type = $this->targetType($item->getFieldDefinition(), $item->getValue());
    $id = $item->target_id ?? NULL;
    return $id !== NULL && $this->isUuidTarget($target_type) ? [$target_type => [$id]] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    $uuid = $item[Snapshot::TARGET_UUID] ?? NULL;
    $target_type = $item[Snapshot::TARGET_TYPE] ?? $this->targetType($definition, []);
    return is_string($uuid) && $uuid !== self::ANONYMOUS && $this->isUuidTarget($target_type) ? [$target_type => [$uuid]] : [];
  }

  /**
   * The target entity type of an item.
   */
  protected function targetType(FieldDefinitionInterface $definition, array $values): string {
    if ($this->storesTargetType($definition) && is_string($values['target_type'] ?? NULL)) {
      return $values['target_type'];
    }
    return (string) $definition->getSetting('target_type');
  }

  /**
   * Whether items store their own target type, as dynamic references do.
   */
  protected function storesTargetType(FieldDefinitionInterface $definition): bool {
    return isset($definition->getFieldStorageDefinition()->getPropertyDefinitions()['target_type']);
  }

  /**
   * Whether a target type is matched across sites by UUID.
   */
  protected function isUuidTarget(string $entity_type_id): bool {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    return $definition instanceof ContentEntityTypeInterface && $definition->hasKey('uuid');
  }

  /**
   * Whether a target type is configuration, matched by machine name.
   */
  protected function isConfigTarget(string $entity_type_id): bool {
    return $this->entityTypeManager->getDefinition($entity_type_id, FALSE) instanceof ConfigEntityTypeInterface;
  }

}
