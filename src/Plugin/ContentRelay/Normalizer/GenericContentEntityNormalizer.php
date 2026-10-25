<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\Normalizer;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntityNormalizerInterface;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerInterface;
use Drupal\content_relay\Serialization\FieldNormalizerManager;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityNullStorage;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
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
 * Every stored field is synced except the ones that only mean something on
 * the site that holds them (see getLocalFieldNames()) and password fields.
 * Values are taken property by property and cast to their declared type, so
 * an integer read back from the database as "1" and the same integer set in
 * memory as 1 produce the same snapshot.
 *
 * Anything site-specific inside a value is the business of the field
 * normalizer for that kind of field (references, embedded entities, links,
 * moderation state), which works the same on every entity type. That is what
 * makes this class enough for entity types nobody wrote a plugin for.
 *
 * Fields of type "changed" go into the metadata section: they are synced so
 * the replica shows the origin's dates, but they are not content, and saving
 * the same values twice must not produce two hashes.
 *
 * Entity types that are composite, meaning they only exist inside a parent
 * through Entity Reference Revisions (paragraphs, and anything else declaring
 * the parent keys), are embedded in their parent's snapshot.
 */
#[ContentRelayNormalizer(
  id: 'generic',
  label: new TranslatableMarkup('Generic content entity'),
  entity_types: ['*'],
  weight: -100,
)]
class GenericContentEntityNormalizer extends PluginBase implements EntityNormalizerInterface, ContainerFactoryPluginInterface {

  /**
   * Entity type keys a composite entity uses to point back at its parent.
   */
  public const PARENT_KEYS = [
    'entity_revision_parent_type_field',
    'entity_revision_parent_id_field',
    'entity_revision_parent_field_name_field',
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected FieldNormalizerManager $fieldNormalizers,
    protected EntityFieldManagerInterface $entityFieldManager,
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
      $container->get('plugin.manager.content_relay_field_normalizer'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(ContentEntityTypeInterface $entity_type): bool {
    // Entity types that are never stored (a contact form message is only
    // sent by mail) have nothing to sync.
    return $entity_type->hasKey('uuid') && !is_a($entity_type->getStorageClass(), ContentEntityNullStorage::class, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isEmbedded(ContentEntityTypeInterface $entity_type): bool {
    return self::isComposite($entity_type);
  }

  /**
   * Whether an entity type lives only inside a parent.
   */
  public static function isComposite(ContentEntityTypeInterface $entity_type): bool {
    return (bool) $entity_type->get('entity_revision_parent_id_field');
  }

  /**
   * {@inheritdoc}
   */
  public function getLocalFieldNames(ContentEntityTypeInterface $entity_type): array {
    // The UUID, bundle and langcode are carried at the top of the snapshot.
    // IDs and revision IDs are local by definition, and revision metadata
    // describes how this site came to have the revision, which on a replica is
    // "synced", not who edited it on the origin. A composite entity's pointer
    // to its parent is set by the parent on each site.
    $names = [];
    foreach (['id', 'revision', 'uuid', 'bundle', 'langcode', 'default_langcode', 'revision_translation_affected'] as $key) {
      if ($name = $entity_type->getKey($key)) {
        $names[] = $name;
      }
    }
    foreach (self::PARENT_KEYS as $key) {
      if ($name = $entity_type->get($key)) {
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
    $entity_type = $entity->getEntityType();
    assert($entity_type instanceof ContentEntityTypeInterface);
    if ($this->hasMeaningfulId($entity_type)) {
      $snapshot['metadata']['id'] = (string) $entity->id();
    }

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
    assert($entity_type instanceof ContentEntityTypeInterface);
    if ($entity === NULL) {
      $values = [$entity_type->getKey('uuid') => $snapshot['uuid']];
      if ($bundle_key = $entity_type->getKey('bundle')) {
        $values[$bundle_key] = $snapshot['bundle'];
      }
      if ($langcode_key = $entity_type->getKey('langcode')) {
        // Without the sender's default, the first language received becomes
        // this copy's default.
        $values[$langcode_key] = Snapshot::langcodes($snapshot)[0];
      }
      if (isset($snapshot['metadata']['id']) && $this->hasMeaningfulId($entity_type)) {
        $values[$entity_type->getKey('id')] = $snapshot['metadata']['id'];
      }
      $entity = $this->entityTypeManager->getStorage($snapshot['entity_type'])->create($values);
      assert($entity instanceof ContentEntityInterface);
    }
    elseif ($entity->bundle() !== $snapshot['bundle']) {
      throw new \InvalidArgumentException(sprintf('Entity %s is a "%s" here and a "%s" in the snapshot; changing bundle is not supported.', $snapshot['uuid'], $entity->bundle(), $snapshot['bundle']));
    }

    $default = $snapshot['default_langcode'];
    if ($entity->getUntranslated()->language()->getId() !== $default) {
      $context->defaultLanguageKept = $entity->getUntranslated()->language()->getId();
      $context->warnings[] = sprintf('Default language is "%s" here and "%s" in the snapshot; the local default was kept.', $entity->getUntranslated()->language()->getId(), $default);
    }
    // Untranslatable fields are the same in every translation, and are set
    // through whichever translation comes first.
    $shared_from = Snapshot::langcodes($snapshot)[0];

    $fields = $this->getSyncedFields($entity);
    $this->recordUnknownFields($snapshot, $fields, $context);
    $this->primeTargets($snapshot, $fields, $serializer);
    $prefix = $context->prefix;

    foreach (Snapshot::langcodes($snapshot) as $langcode) {
      // The default translation by identity, not by lookup: an entity whose
      // language is "not specified" does not report that it "has" it.
      if ($langcode === $entity->getUntranslated()->language()->getId()) {
        $translation = $entity->getUntranslated();
      }
      elseif ($entity->hasTranslation($langcode)) {
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
        // Preserved fields are the applied entity's, not embedded ones'.
        if ($context->prefix === [] && in_array($name, $context->preserveFields, TRUE)) {
          continue;
        }
        $shared = !$this->isTranslatableField($entity, $definition);
        if ($shared && $langcode !== $shared_from) {
          continue;
        }
        $metadata = $this->isMetadataField($definition);
        $location = $metadata
          ? ($shared ? ['metadata', 'shared', $name] : ['metadata', 'translations', $langcode, $name])
          : ($shared ? ['shared', $name] : ['translations', $langcode, $name]);
        $items = $this->itemsAt($snapshot, $location);
        if ($metadata && $items === NULL) {
          // Metadata is informative: when the sender left it out, keep ours
          // rather than clearing it.
          continue;
        }
        // An absent field is an empty one; see Snapshot.
        $context->location = [...$prefix, ...$location];
        $translation->set($name, $this->denormalizeItems($items ?? [], $definition, $context, $serializer));
      }
    }
    $context->prefix = $prefix;

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
   * Whether an entity type's IDs are chosen rather than generated.
   *
   * Integer IDs are assigned by each site's database and mean nothing
   * elsewhere. String IDs are chosen when the entity is created, nothing
   * generates them, and an entity cannot be saved without one, so they travel
   * with the snapshot, as metadata: identity is still the UUID, and the hash
   * is unaffected.
   */
  protected function hasMeaningfulId(ContentEntityTypeInterface $entity_type): bool {
    $id_key = (string) $entity_type->getKey('id');
    $definition = $this->entityFieldManager->getBaseFieldDefinitions($entity_type->id())[$id_key] ?? NULL;
    return $definition !== NULL && $definition->getType() !== 'integer';
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
    return $this->filterSyncedFields($entity_type, $entity->getFieldDefinitions());
  }

  /**
   * {@inheritdoc}
   */
  public function getSyncedFieldDefinitions(ContentEntityTypeInterface $entity_type, string $bundle): array {
    return $this->filterSyncedFields($entity_type, $this->entityFieldManager->getFieldDefinitions($entity_type->id(), $bundle));
  }

  /**
   * Keeps the synced fields of a set of definitions, sorted by name.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   The entity type.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $definitions
   *   Field definitions.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   The synced ones.
   */
  protected function filterSyncedFields(ContentEntityTypeInterface $entity_type, array $definitions): array {
    $local = array_flip($this->getLocalFieldNames($entity_type));
    $fields = [];
    foreach ($definitions as $name => $definition) {
      if (isset($local[$name]) || $definition->getType() === 'password') {
        continue;
      }
      if ($definition->isComputed() && !$this->fieldNormalizers->getForField($definition)?->syncsComputed()) {
        continue;
      }
      $fields[$name] = $definition;
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
    $langcodes = [];
    foreach (array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
      if (self::wanted((string) $langcode, $context->langcodes)) {
        $langcodes[] = (string) $langcode;
      }
    }
    sort($langcodes);
    // The receiver's filter leaves the default translation out of the item
    // it gets, but embedded entities always keep theirs: they are shown in
    // the parent's languages, falling back to their default.
    if (!self::wanted($default, $context->langcodes) && $langcodes !== [] && count($context->stack) <= 1) {
      return $langcodes;
    }
    return [$default, ...$langcodes];
  }

  /**
   * Whether a language passes a receiver's filter.
   *
   * Language-neutral content passes any filter.
   *
   * @param string $langcode
   *   The langcode.
   * @param string[]|null $filter
   *   Langcodes the receiver wants, or NULL for all.
   */
  public static function wanted(string $langcode, ?array $filter): bool {
    return $filter === NULL || in_array($langcode, $filter, TRUE) || in_array($langcode, [
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      LanguageInterface::LANGCODE_NOT_APPLICABLE,
    ], TRUE);
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
      $normalizer = $this->fieldNormalizers->getForField($definition);
      if ($normalizer === NULL || $definition->isComputed()) {
        continue;
      }
      foreach ($langcodes as $langcode) {
        foreach ($entity->getTranslation($langcode)->get($name) as $item) {
          foreach ($normalizer->referencedIds($item) as $entity_type_id => $list) {
            $ids[$entity_type_id] = [...($ids[$entity_type_id] ?? []), ...$list];
          }
        }
      }
    }
    foreach ($ids as $entity_type_id => $list) {
      $serializer->getUuidResolver()->primeIds($entity_type_id, $list);
    }
  }

  /**
   * Looks up the local IDs of every referenced UUID in one query per type.
   *
   * @param array $snapshot
   *   The snapshot.
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   Local synced fields.
   * @param \Drupal\content_relay\Serialization\EntitySerializer $serializer
   *   The serializer.
   */
  protected function primeTargets(array $snapshot, array $fields, EntitySerializer $serializer): void {
    $uuids = [];
    $sections = [$snapshot['shared'], ...array_values($snapshot['translations'])];
    foreach ($fields as $name => $definition) {
      $normalizer = $this->fieldNormalizers->getForField($definition);
      if ($normalizer === NULL) {
        continue;
      }
      foreach ($sections as $section) {
        foreach ($section[$name] ?? [] as $item) {
          foreach ($normalizer->referencedUuids($item, $definition) as $entity_type_id => $list) {
            $uuids[$entity_type_id] = [...($uuids[$entity_type_id] ?? []), ...$list];
          }
        }
      }
    }
    foreach ($uuids as $entity_type_id => $list) {
      if ($this->entityTypeManager->hasDefinition($entity_type_id)) {
        $serializer->getUuidResolver()->primeUuids($entity_type_id, $list);
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
    $normalizer = $this->fieldNormalizers->getForField($items->getFieldDefinition());
    $normalized = [];
    foreach ($items as $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $value = $this->normalizeItem($item, $normalizer, $context, $serializer);
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
  protected function normalizeItem(FieldItemInterface $item, ?FieldNormalizerInterface $normalizer, NormalizationContext $context, EntitySerializer $serializer): ?array {
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
    return $normalizer === NULL ? $values : $normalizer->normalizeItem($item, $values, $context, $serializer);
  }

  /**
   * Turns normalized items back into values a field accepts.
   *
   * @return list<array>
   *   Field values.
   */
  protected function denormalizeItems(array $items, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): array {
    $normalizer = $this->fieldNormalizers->getForField($definition);
    if ($normalizer === NULL) {
      return array_values($items);
    }
    $location = $context->location;
    $values = [];
    foreach ($items as $delta => $item) {
      $context->location = $location;
      $context->delta = (int) $delta;
      $item = $normalizer->denormalizeItem($item, $definition, $context, $serializer);
      if ($item !== NULL) {
        $values[] = $item;
      }
    }
    $context->location = $location;
    return $values;
  }

  /**
   * The item list at a location in a snapshot, or NULL if absent.
   */
  protected function itemsAt(array $snapshot, array $location): ?array {
    foreach ($location as $key) {
      if (!is_array($snapshot) || !array_key_exists($key, $snapshot)) {
        return NULL;
      }
      $snapshot = $snapshot[$key];
    }
    return is_array($snapshot) ? $snapshot : NULL;
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
      throw new \InvalidArgumentException(sprintf('Field "%s" holds a %s, which cannot be synced. Add a field normalizer plugin for this field type.', $item->getFieldDefinition()->getName(), get_debug_type($value)));
    }
    return $value;
  }

}
