<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerBase;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay\Serialization\PathTranslator;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plain string fields that hold a system path, such as a path alias's path.
 *
 * "/node/5" is made portable like a link URI. Which fields hold system paths
 * cannot be told from the field type (it is a string), so they are listed by
 * entity type and field name in PATH_FIELDS; a module can add to it with
 * hook_content_relay_field_normalizer_info_alter().
 */
#[ContentRelayFieldNormalizer(
  id: 'local_path',
  label: new TranslatableMarkup('System path'),
)]
class LocalPath extends FieldNormalizerBase implements ContainerFactoryPluginInterface {

  /**
   * String fields holding a system path, by entity type.
   */
  public const PATH_FIELDS = [
    'path_alias' => ['path'],
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected PathTranslator $paths,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('content_relay.path_translator'));
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    $fields = ($this->pluginDefinition['path_fields'] ?? NULL) ?: self::PATH_FIELDS;
    return in_array($definition->getName(), $fields[$definition->getTargetEntityTypeId()] ?? [], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    if (is_string($values['value'] ?? NULL)) {
      $values['value'] = $this->paths->toPortable($values['value'], $context);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    return is_string($item['value'] ?? NULL) ? self::resolve($this->paths, $item, 'value', $definition, $context) : $item;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    return is_string($item['value'] ?? NULL) ? $this->paths->tokens($item['value']) : [];
  }

  /**
   * Resolves tokens in one property of an item.
   *
   * A path to an entity outside the subscription cannot be made to work
   * here, so the item is dropped and the drop recorded; a path to one that
   * has simply not arrived yet waits for it.
   *
   * @return array|null
   *   The local item, or NULL to leave it out.
   */
  public static function resolve(PathTranslator $paths, array $item, string $property, FieldDefinitionInterface $definition, DenormalizationContext $context): ?array {
    $missing = new DenormalizationContext();
    $local = $paths->toLocal($item[$property], $missing, $definition->getName());
    if ($local !== NULL) {
      $item[$property] = $local;
      return $item;
    }
    foreach ($missing->missingReferences as $reference) {
      if (!$context->isExternal($reference['entity_type'], $reference['uuid'])) {
        $context->addMissingReference($reference['entity_type'], $reference['uuid'], $definition->getName());
        return NULL;
      }
    }
    $context->substitute(NULL);
    return NULL;
  }

}
