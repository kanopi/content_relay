<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\Normalizer;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntityNormalizerInterface;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\PrimitiveInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Normalizes any content entity type with a UUID.
 *
 * Every stored, non-computed field is synced except the ones that only mean
 * something on the site that holds them (see getLocalFieldNames()). Values are
 * taken property by property and cast to their declared type, so an integer
 * read back from the database as "1" and the same integer set in memory as 1
 * produce the same snapshot.
 *
 * References to content entities become UUIDs; references to configuration
 * entities keep their machine name, which is already portable. Entities whose
 * own normalizer says they are embedded (paragraphs) are normalized into the
 * referencing item.
 *
 * Fields of type "changed" go into the metadata section: they are synced so
 * the replica shows the origin's dates, but they are not content, and saving
 * the same values twice must not produce two hashes.
 *
 * Subclasses handle the special cases; most need only override
 * getLocalFieldNames() or normalizeItem().
 */
#[ContentRelayNormalizer(
  id: 'generic',
  label: new TranslatableMarkup('Generic content entity'),
  entity_types: ['*'],
  weight: -100,
)]
class GenericContentEntityNormalizer extends PluginBase implements EntityNormalizerInterface, ContainerFactoryPluginInterface {

  /**
   * The user ID that is the same on every site.
   *
   * Anonymous is user 0 everywhere but its UUID is generated per site, so it
   * cannot be matched by UUID like everything else.
   */
  protected const ANONYMOUS = 'anonymous';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(ContentEntityTypeInterface $entity_type): bool {
    return $entity_type->hasKey('uuid');
  }

  /**
   * {@inheritdoc}
   */
  public function isEmbedded(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getLocalFieldNames(ContentEntityTypeInterface $entity_type): array {
    // The UUID, bundle and langcode are carried at the top of the snapshot.
    // IDs and revision IDs are local by definition, and revision metadata
    // describes how this site came to have the revision, which on a replica is
    // "synced", not who edited it on the origin.
    $names = [];
    foreach (['id', 'revision', 'uuid', 'bundle', 'langcode', 'default_langcode', 'revision_translation_affected'] as $key) {
      if ($name = $entity_type->getKey($key)) {
        $names[] = $name;
      }
    }
    return array_values(array_unique([...$names, ...array_values($entity_type->getRevisionMetadataKeys())]));
  }

  /**
   * {@inheritdoc}
   */
  public function normalize(ContentEntityInterface $entity, NormalizationContext $context, EntitySerializer $serializer): array {
    $fields = $this->getSyncedFields($entity);
    $default = $entity->getUntranslated()->language()->getId();
    $langcodes = $this->selectLangcodes($entity, $context);
    $this->primeReferences($entity, $fields, $langcodes, $serializer);

    $snapshot = [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'uuid' => $entity->uuid(),
      'default_langcode' => $default,
      'shared' => [],
      'translations' => [],
      'metadata' => ['shared' => [], 'translations' => []],
    ];

    foreach ($langcodes as $langcode) {
      $translation = $entity->getTranslation($langcode);
      $snapshot['translations'][$langcode] = [];
      foreach ($fields as $name => $definition) {
        $shared = !$this->isTranslatableField($entity, $definition);
        if ($shared && $langcode !== $default) {
          continue;
        }
        $items = $this->normalizeField($translation->get($name), $context, $serializer);
        if ($items === []) {
          continue;
        }
        if ($this->isMetadataField($definition)) {
          if ($shared) {
            $snapshot['metadata']['shared'][$name] = $items;
          }
          else {
            $snapshot['metadata']['translations'][$langcode][$name] = $items;
          }
        }
        elseif ($shared) {
          $snapshot['shared'][$name] = $items;
        }
        else {
          $snapshot['translations'][$langcode][$name] = $items;
        }
      }
    }
    return $snapshot;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize(array $snapshot, ?ContentEntityInterface $entity, DenormalizationContext $context, EntitySerializer $serializer): ContentEntityInterface {
    $entity_type = $this->entityTypeManager->getDefinition($snapshot['entity_type']);
    if ($entity === NULL) {
      $values = [$entity_type->getKey('uuid') => $snapshot['uuid']];
      if ($bundle_key = $entity_type->getKey('bundle')) {
        $values[$bundle_key] = $snapshot['bundle'];
      }
      if ($langcode_key = $entity_type->getKey('langcode')) {
        $values[$langcode_key] = $snapshot['default_langcode'];
      }
      $entity = $this->entityTypeManager->getStorage($snapshot['entity_type'])->create($values);
      assert($entity instanceof ContentEntityInterface);
    }
    elseif ($entity->bundle() !== $snapshot['bundle']) {
      throw new \InvalidArgumentException(sprintf('Entity %s is a "%s" here and a "%s" in the snapshot; changing bundle is not supported.', $snapshot['uuid'], $entity->bundle(), $snapshot['bundle']));
    }

    $default = $snapshot['default_langcode'];
    if ($entity->getUntranslated()->language()->getId() !== $default) {
      $context->warnings[] = sprintf('Default language is "%s" here and "%s" in the snapshot; the local default was kept.', $entity->getUntranslated()->language()->getId(), $default);
    }

    $fields = $this->getSyncedFields($entity);
    $this->recordUnknownFields($snapshot, $fields, $context);
    $this->primeTargets($snapshot, $serializer);

    foreach (Snapshot::langcodes($snapshot) as $langcode) {
      if ($entity->hasTranslation($langcode)) {
        $translation = $entity->getTranslation($langcode);
      }
      elseif (!$entity->isTranslatable()) {
        $context->skippedTranslations[$langcode] = 'The entity is not translatable here.';
        continue;
      }
      elseif (!$this->languageManager->getLanguage($langcode)) {
        $context->skippedTranslations[$langcode] = 'The language does not exist here.';
        continue;
      }
      else {
        $translation = $entity->addTranslation($langcode);
      }

      foreach ($fields as $name => $definition) {
        if (in_array($name, $context->preserveFields, TRUE)) {
          continue;
        }
        $shared = !$this->isTranslatableField($entity, $definition);
        if ($shared && $langcode !== $default) {
          continue;
        }
        if ($this->isMetadataField($definition)) {
          $items = $shared ? ($snapshot['metadata']['shared'][$name] ?? NULL) : ($snapshot['metadata']['translations'][$langcode][$name] ?? NULL);
          // Metadata is informative: when the sender left it out, keep ours
          // rather than clearing it.
          if ($items === NULL) {
            continue;
          }
        }
        else {
          // An absent field is an empty one; see Snapshot.
          $items = $shared ? ($snapshot['shared'][$name] ?? []) : ($snapshot['translations'][$langcode][$name] ?? []);
        }
        $translation->set($name, $this->denormalizeItems($items, $definition, $context, $serializer));
      }
    }

    // Managed translations the sender no longer has were deleted there.
    foreach (array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
      $managed = $context->managedLangcodes === NULL || in_array($langcode, $context->managedLangcodes, TRUE);
      if ($managed && !isset($snapshot['translations'][$langcode])) {
        $entity->removeTranslation($langcode);
      }
    }

    return $entity;
  }

  /**
   * Fields that are synced, keyed by name, in a stable order.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Field definitions.
   */
  protected function getSyncedFields(ContentEntityInterface $entity): array {
    $entity_type = $entity->getEntityType();
    assert($entity_type instanceof ContentEntityTypeInterface);
    $local = array_flip($this->getLocalFieldNames($entity_type));
    $fields = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!isset($local[$name]) && !$definition->isComputed()) {
        $fields[$name] = $definition;
      }
    }
    ksort($fields);
    return $fields;
  }

  /**
   * Whether a field's values differ per translation of this entity.
   */
  protected function isTranslatableField(ContentEntityInterface $entity, FieldDefinitionInterface $definition): bool {
    return $entity->isTranslatable() && $definition->isTranslatable();
  }

  /**
   * Whether a field is synced but kept out of hashes.
   */
  protected function isMetadataField(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'changed';
  }

  /**
   * The translations to include, default first.
   *
   * @return string[]
   *   Langcodes.
   */
  protected function selectLangcodes(ContentEntityInterface $entity, NormalizationContext $context): array {
    $default = $entity->getUntranslated()->language()->getId();
    $neutral = [LanguageInterface::LANGCODE_NOT_SPECIFIED, LanguageInterface::LANGCODE_NOT_APPLICABLE];
    $langcodes = [];
    foreach (array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
      if ($context->langcodes === NULL || in_array($langcode, $context->langcodes, TRUE) || in_array($langcode, $neutral, TRUE)) {
        $langcodes[] = (string) $langcode;
      }
    }
    sort($langcodes);
    return [$default, ...$langcodes];
  }

  /**
   * Looks up the UUIDs of every referenced entity in one query per type.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   Synced fields.
   * @param string[] $langcodes
   *   Translations being normalized.
   * @param \Drupal\content_relay\Serialization\EntitySerializer $serializer
   *   The serializer.
   */
  protected function primeReferences(ContentEntityInterface $entity, array $fields, array $langcodes, EntitySerializer $serializer): void {
    $ids = [];
    foreach ($fields as $name => $definition) {
      if (!$this->isReferenceField($definition)) {
        continue;
      }
      $target_type = (string) $definition->getSetting('target_type');
      foreach ($langcodes as $langcode) {
        foreach ($entity->getTranslation($langcode)->get($name) as $item) {
          if (isset($item->target_id)) {
            $ids[$target_type][] = $item->target_id;
          }
        }
      }
    }
    foreach ($ids as $target_type => $list) {
      if ($this->isUuidTarget($target_type)) {
        $serializer->getUuidResolver()->primeIds($target_type, $list);
      }
    }
  }

  /**
   * Looks up the local IDs of every referenced UUID in one query per type.
   */
  protected function primeTargets(array $snapshot, EntitySerializer $serializer): void {
    $uuids = [];
    $collect = function (array $fields) use (&$uuids): void {
      foreach ($fields as $items) {
        foreach ($items as $item) {
          if (isset($item[Snapshot::TARGET_TYPE], $item[Snapshot::TARGET_UUID])) {
            $uuids[$item[Snapshot::TARGET_TYPE]][] = $item[Snapshot::TARGET_UUID];
          }
        }
      }
    };
    $collect($snapshot['shared']);
    array_map($collect, $snapshot['translations']);
    foreach ($uuids as $target_type => $list) {
      if ($this->entityTypeManager->hasDefinition($target_type) && $this->isUuidTarget($target_type)) {
        $serializer->getUuidResolver()->primeUuids($target_type, $list);
      }
    }
  }

  /**
   * Normalizes the non-empty items of a field.
   *
   * @return list<array>
   *   Items.
   */
  protected function normalizeField(FieldItemListInterface $items, NormalizationContext $context, EntitySerializer $serializer): array {
    $normalized = [];
    foreach ($items as $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $value = $this->normalizeItem($item, $context, $serializer);
      if ($value !== NULL) {
        $normalized[] = $value;
      }
    }
    return $normalized;
  }

  /**
   * Normalizes one field item.
   *
   * @return array|null
   *   The item's properties, or NULL to leave the item out.
   */
  protected function normalizeItem(FieldItemInterface $item, NormalizationContext $context, EntitySerializer $serializer): ?array {
    $values = [];
    // Stored properties only: computed ones are derived from these on the
    // receiving site, and include objects such as the referenced entity.
    foreach ($item->getProperties() as $name => $property) {
      $value = $property->getValue();
      if ($value === NULL) {
        continue;
      }
      // Casting is what makes a value read from the database ("1") and the
      // same value set in memory (1 or TRUE) hash identically. NULL was
      // handled above because casting would turn it into 0 or "".
      $values[$name] = $property instanceof PrimitiveInterface ? $property->getCastedValue() : $this->plainValue($value, $item);
    }
    if ($item instanceof EntityReferenceItem) {
      return $this->normalizeReference($item, $values, $context, $serializer);
    }
    return $values;
  }

  /**
   * Replaces a local target ID with something portable.
   *
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $item
   *   The item.
   * @param array $values
   *   Its normalized properties.
   * @param \Drupal\content_relay\Serialization\NormalizationContext $context
   *   The context.
   * @param \Drupal\content_relay\Serialization\EntitySerializer $serializer
   *   The serializer.
   *
   * @return array|null
   *   The item, or NULL when its target is gone.
   */
  protected function normalizeReference(EntityReferenceItem $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    $target_type = (string) $item->getFieldDefinition()->getSetting('target_type');
    $field = $item->getFieldDefinition()->getName();
    $id = $values['target_id'] ?? NULL;
    unset($values['target_id'], $values['target_revision_id']);
    if ($id === NULL) {
      // An unsaved target: nothing on the other site can match it.
      return NULL;
    }
    $values[Snapshot::TARGET_TYPE] = $target_type;

    if ($this->isConfigTarget($target_type)) {
      $values['target_id'] = (string) $id;
      return $values;
    }
    if ($target_type === 'user' && (int) $id === 0) {
      $values[Snapshot::TARGET_UUID] = self::ANONYMOUS;
      return $values;
    }
    if (!$this->isUuidTarget($target_type)) {
      $context->unresolved[] = ['field' => $field, 'entity_type' => $target_type, 'id' => $id];
      return NULL;
    }

    if ($serializer->getNormalizer($target_type)->isEmbedded()) {
      $target = $item->entity;
      if (!$target instanceof ContentEntityInterface) {
        $context->unresolved[] = ['field' => $field, 'entity_type' => $target_type, 'id' => $id];
        return NULL;
      }
      $values[Snapshot::EMBEDDED] = $serializer->normalize($target, $context);
      return $values;
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
   * Turns normalized items back into values a field accepts.
   *
   * @return list<array>
   *   Field values.
   */
  protected function denormalizeItems(array $items, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): array {
    $values = [];
    $is_reference = $this->isReferenceField($definition);
    foreach ($items as $item) {
      if ($is_reference) {
        $item = $this->denormalizeReference($item, $definition, $context, $serializer);
        if ($item === NULL) {
          continue;
        }
      }
      $values[] = $item;
    }
    return $values;
  }

  /**
   * Resolves a portable reference to a local target.
   *
   * @return array|null
   *   The item, or NULL when the target does not exist here.
   */
  protected function denormalizeReference(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    $target_type = $item[Snapshot::TARGET_TYPE] ?? (string) $definition->getSetting('target_type');
    $uuid = $item[Snapshot::TARGET_UUID] ?? NULL;
    unset($item[Snapshot::TARGET_TYPE], $item[Snapshot::TARGET_UUID]);

    if (isset($item[Snapshot::EMBEDDED])) {
      $item[Snapshot::EMBEDDED] = $serializer->denormalize($item[Snapshot::EMBEDDED], $context);
      return $item;
    }
    if ($this->isConfigTarget($target_type)) {
      return isset($item['target_id']) ? $item : NULL;
    }
    if ($target_type === 'user' && $uuid === self::ANONYMOUS) {
      return ['target_id' => 0] + $item;
    }
    if (!is_string($uuid)) {
      return NULL;
    }
    $id = $serializer->getUuidResolver()->toId($target_type, $uuid);
    if ($id === NULL) {
      $context->addMissingReference($target_type, $uuid, $definition->getName());
      return NULL;
    }
    return ['target_id' => $id] + $item;
  }

  /**
   * Records snapshot fields this entity does not have.
   *
   * @param array $snapshot
   *   The snapshot.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   Local synced fields.
   * @param \Drupal\content_relay\Serialization\DenormalizationContext $context
   *   The context.
   */
  protected function recordUnknownFields(array $snapshot, array $fields, DenormalizationContext $context): void {
    $names = array_keys($snapshot['shared']);
    foreach ($snapshot['translations'] as $translation) {
      $names = [...$names, ...array_keys($translation)];
    }
    foreach (array_diff(array_unique($names), array_keys($fields)) as $name) {
      $context->unknownFields[(string) $name] = TRUE;
    }
  }

  /**
   * Whether a field's items reference other entities by ID.
   */
  protected function isReferenceField(FieldDefinitionInterface $definition): bool {
    return is_a($definition->getItemDefinition()->getClass(), EntityReferenceItem::class, TRUE);
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

  /**
   * Converts a non-primitive property value to scalars and arrays.
   *
   * @throws \InvalidArgumentException
   *   When the value holds an object, which would not survive the trip.
   */
  protected function plainValue(mixed $value, FieldItemInterface $item): mixed {
    if (is_array($value)) {
      return array_map(fn ($nested) => $this->plainValue($nested, $item), $value);
    }
    if (is_object($value) || is_resource($value)) {
      throw new \InvalidArgumentException(sprintf('Field "%s" holds a %s, which cannot be synced. Add a normalizer plugin for this entity type that handles it.', $item->getFieldDefinition()->getName(), get_debug_type($value)));
    }
    return $value;
  }

}
