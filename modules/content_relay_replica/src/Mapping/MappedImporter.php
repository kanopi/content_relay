<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerManager;
use Drupal\content_relay\Serialization\NormalizerManager;
use Drupal\content_relay\Serialization\Snapshot;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Builds an item on this site's structure from an origin snapshot.
 *
 * For each language of the snapshot, each field mapping reads its source
 * (a field's items, or one property of each item), runs its transforms, and
 * writes the result to its target field or property. A whole field mapped
 * without transforms goes through the target field's normalizer, so
 * references, links and file tokens resolve as they do for content taken as
 * it is; with transforms, the last transform's output is written as it is.
 *
 * A target field that is not translatable here is filled once, from the
 * origin language the mapping names (default: the origin's default). A
 * source field that is not translatable on the origin feeds every target
 * translation.
 *
 * Origin fields the mapping neither reads nor ignores are recorded in the
 * context as unknown fields: schema drift. Known fields are still applied.
 */
class MappedImporter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LanguageManagerInterface $languageManager,
    protected FieldNormalizerManager $fieldNormalizers,
    protected NormalizerManager $normalizers,
    protected TransformManager $transforms,
    protected EntitySerializer $serializer,
  ) {}

  /**
   * Builds the target entity, not saved.
   *
   * @param array $snapshot
   *   The origin snapshot.
   * @param \Drupal\content_relay_replica\Entity\ContentRelayMapping $mapping
   *   The mapping.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The existing item here, or NULL to create it.
   * @param \Drupal\content_relay\Serialization\DenormalizationContext $context
   *   Collects missing references and unknown fields.
   *
   * @throws \Drupal\content_relay_replica\Mapping\MappingException
   *   When the item cannot be built under the mapping.
   */
  public function build(array $snapshot, ContentRelayMapping $mapping, ?ContentEntityInterface $entity, DenormalizationContext $context): ContentEntityInterface {
    $entity_type = $this->entityTypeManager->getDefinition($mapping->getTargetEntityType(), FALSE);
    if (!$entity_type instanceof ContentEntityTypeInterface) {
      throw new MappingException(sprintf('The mapping targets entity type "%s", which does not exist here.', $mapping->getTargetEntityType()));
    }
    $definitions = $this->entityFieldManager->getFieldDefinitions($mapping->getTargetEntityType(), $mapping->getTargetBundle());
    $entity ??= $this->create($entity_type, $mapping, $snapshot);
    if ($entity->bundle() !== $mapping->getTargetBundle()) {
      throw new MappingException(sprintf('Item %s is a "%s" here; the mapping makes "%s".', $snapshot['uuid'], $entity->bundle(), $mapping->getTargetBundle()));
    }
    $this->checkRequired($mapping, $entity_type, $definitions);
    $this->recordUnknownFields($snapshot, $mapping, $context);

    $default = $snapshot['default_langcode'];
    $untranslatable_from = $mapping->getUntranslatableSource() === 'default' ? $default : $mapping->getUntranslatableSource();
    if (!isset($snapshot['translations'][$untranslatable_from])) {
      $untranslatable_from = $default;
    }
    $local_only = array_flip($mapping->getLocalOnlyFields());

    foreach (Snapshot::langcodes($snapshot) as $langcode) {
      $translation = $this->translation($entity, $langcode, $context);
      if ($translation === NULL) {
        continue;
      }
      $values = [];
      foreach ($mapping->getFieldMappings() as $field_mapping) {
        [$field, $property] = explode('.', $field_mapping['target'], 2) + [1 => NULL];
        $definition = $definitions[$field] ?? throw new MappingException(sprintf('The mapping writes "%s", which %s %s does not have.', $field, $mapping->getTargetEntityType(), $mapping->getTargetBundle()));
        if (isset($local_only[$field])) {
          continue;
        }
        $translatable = $entity->isTranslatable() && $definition->isTranslatable();
        $source_langcode = $translatable ? $langcode : $untranslatable_from;
        if (!$translatable && $langcode !== $translation->getUntranslated()->language()->getId()) {
          continue;
        }
        $items = $this->read($snapshot, $field_mapping['source'], $source_langcode);
        $process = $field_mapping['process'];
        if ($process !== []) {
          $items = $this->transform($items, $process, $snapshot, $source_langcode, $field_mapping);
        }
        elseif ($property === NULL) {
          $items = $this->denormalize($items, $definition, $context);
        }
        if ($property === NULL) {
          $values[$field] = array_values(array_map(fn ($item) => is_array($item) ? $item : ['value' => $item], $items));
        }
        else {
          foreach (array_values($items) as $delta => $value) {
            $values[$field][$delta][$property] = is_array($value) && array_key_exists($property, $value) ? $value[$property] : $value;
          }
          $values[$field] ??= [];
        }
      }
      foreach ($values as $field => $items) {
        $translation->set($field, array_values($items));
      }
    }
    return $entity;
  }

  /**
   * Problems with a mapping, before it is saved.
   *
   * @return string[]
   *   Messages; empty when it can be used.
   */
  public function validate(ContentRelayMapping $mapping): array {
    $entity_type = $this->entityTypeManager->getDefinition($mapping->getTargetEntityType(), FALSE);
    if (!$entity_type instanceof ContentEntityTypeInterface) {
      return [sprintf('Entity type "%s" does not exist here.', $mapping->getTargetEntityType())];
    }
    $definitions = $this->entityFieldManager->getFieldDefinitions($mapping->getTargetEntityType(), $mapping->getTargetBundle());
    $problems = [];
    foreach ($mapping->getFieldMappings() as $field_mapping) {
      [$field, $property] = explode('.', $field_mapping['target'], 2) + [1 => NULL];
      $definition = $definitions[$field] ?? NULL;
      if ($definition === NULL) {
        $problems[] = sprintf('Field "%s" does not exist on %s.', $field, $mapping->getTargetBundle());
        continue;
      }
      if ($property !== NULL && !isset($definition->getFieldStorageDefinition()->getPropertyDefinitions()[$property])) {
        $problems[] = sprintf('Field "%s" has no property "%s".', $field, $property);
      }
      if ($field_mapping['source'] === '' && $field_mapping['process'] === []) {
        $problems[] = sprintf('"%s" has neither a source nor transforms.', $field_mapping['target']);
      }
      foreach ($field_mapping['process'] as $step) {
        $plugin_id = (string) ($step['plugin'] ?? '');
        if (!$this->transforms->hasDefinition($plugin_id)) {
          $problems[] = sprintf('Transform "%s" for "%s" does not exist.', $plugin_id, $field_mapping['target']);
          continue;
        }
        foreach ($this->transforms->createInstance($plugin_id, (array) ($step['configuration'] ?? []))->validateConfiguration() as $message) {
          $problems[] = sprintf('%s (%s, for "%s")', $message, $plugin_id, $field_mapping['target']);
        }
      }
    }
    try {
      $this->checkRequired($mapping, $entity_type, $definitions);
    }
    catch (MappingException $e) {
      $problems[] = $e->getMessage();
    }
    return $problems;
  }

  /**
   * The values of a source field or property in a language.
   *
   * @return array
   *   Items for a field, property values for a property.
   */
  public function read(array $snapshot, string $source, string $langcode): array {
    if ($source === '') {
      return [];
    }
    [$field, $property] = explode('.', $source, 2) + [1 => NULL];
    $items = $snapshot['shared'][$field] ?? $snapshot['translations'][$langcode][$field] ?? $snapshot['translations'][$snapshot['default_langcode']][$field] ?? [];
    if ($property === NULL) {
      return array_values($items);
    }
    $values = [];
    foreach ($items as $item) {
      if (is_array($item) && array_key_exists($property, $item)) {
        $values[] = $item[$property];
      }
    }
    return $values;
  }

  /**
   * Runs a field mapping's transforms.
   */
  protected function transform(array $values, array $process, array $snapshot, string $langcode, array $field_mapping): array {
    $context = new TransformContext($snapshot, $langcode, $field_mapping['source'], $field_mapping['target'], fn (string $path, string $langcode) => $this->read($snapshot, $path, $langcode));
    foreach ($process as $step) {
      $plugin = $this->transforms->createInstance((string) $step['plugin'], (array) ($step['configuration'] ?? []));
      $values = array_values($plugin->transform($values, $context));
    }
    return $values;
  }

  /**
   * Turns portable items into local values with the target's field normalizer.
   */
  protected function denormalize(array $items, FieldDefinitionInterface $definition, DenormalizationContext $context): array {
    $normalizer = $this->fieldNormalizers->getForField($definition);
    if ($normalizer === NULL) {
      return $items;
    }
    $values = [];
    foreach (array_values($items) as $delta => $item) {
      $context->delta = $delta;
      $local = $normalizer->denormalizeItem($item, $definition, $context, $this->serializer);
      if ($local !== NULL) {
        $values[] = $local;
      }
    }
    return $values;
  }

  /**
   * Refuses a mapping that leaves a required field here without a value.
   *
   * @throws \Drupal\content_relay_replica\Mapping\MappingException
   */
  protected function checkRequired(ContentRelayMapping $mapping, ContentEntityTypeInterface $entity_type, array $definitions): void {
    if (!$mapping->rejectsUnmappedRequired()) {
      return;
    }
    $mapped = [];
    foreach ($mapping->getFieldMappings() as $field_mapping) {
      $mapped[explode('.', $field_mapping['target'], 2)[0]] = TRUE;
    }
    $synced = $this->normalizers->getNormalizer($entity_type->id())->getSyncedFieldDefinitions($entity_type, $mapping->getTargetBundle());
    foreach ($synced as $name => $definition) {
      if ($definition->isRequired() && !isset($mapped[$name]) && !in_array($name, $mapping->getLocalOnlyFields(), TRUE)
        && $definition->getDefaultValueLiteral() === [] && $definition->getDefaultValueCallback() === NULL) {
        throw new MappingException(sprintf('Required field "%s" here is not mapped and has no default value.', $definitions[$name]?->getLabel() ?? $name));
      }
    }
  }

  /**
   * Records origin fields the mapping neither reads nor ignores.
   */
  protected function recordUnknownFields(array $snapshot, ContentRelayMapping $mapping, DenormalizationContext $context): void {
    $names = array_keys($snapshot['shared']);
    foreach ($snapshot['translations'] as $fields) {
      $names = [...$names, ...array_keys($fields)];
    }
    $known = [...$mapping->getMappedSourceFields(), ...$mapping->getIgnoredSourceFields()];
    foreach (array_diff(array_unique($names), $known) as $name) {
      $context->unknownFields[(string) $name] = TRUE;
    }
  }

  /**
   * A new target entity for a snapshot.
   */
  protected function create(ContentEntityTypeInterface $entity_type, ContentRelayMapping $mapping, array $snapshot): ContentEntityInterface {
    $values = [(string) $entity_type->getKey('uuid') => $snapshot['uuid']];
    if ($bundle_key = $entity_type->getKey('bundle')) {
      $values[$bundle_key] = $mapping->getTargetBundle();
    }
    if (($langcode_key = $entity_type->getKey('langcode')) && $this->languageManager->getLanguage($snapshot['default_langcode'])) {
      $values[$langcode_key] = $snapshot['default_langcode'];
    }
    $entity = $this->entityTypeManager->getStorage($entity_type->id())->create($values);
    assert($entity instanceof ContentEntityInterface);
    return $entity;
  }

  /**
   * The target translation for a langcode, created when needed.
   */
  protected function translation(ContentEntityInterface $entity, string $langcode, DenormalizationContext $context): ?ContentEntityInterface {
    if ($langcode === $entity->getUntranslated()->language()->getId()) {
      return $entity->getUntranslated();
    }
    if ($entity->hasTranslation($langcode)) {
      return $entity->getTranslation($langcode);
    }
    if (!$entity->isTranslatable()) {
      $context->skippedTranslations[$langcode] = 'The entity is not translatable here.';
      return NULL;
    }
    if (!$this->languageManager->getLanguage($langcode)) {
      $context->skippedTranslations[$langcode] = 'The language does not exist here.';
      return NULL;
    }
    return $entity->addTranslation($langcode);
  }

}
