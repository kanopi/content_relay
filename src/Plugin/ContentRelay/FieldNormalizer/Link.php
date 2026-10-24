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
 * Link fields whose URI points at a local entity, on any entity type.
 *
 * "entity:node/5", "internal:/node/5" and route URIs carry a local ID; they
 * travel with a UUID token instead. External URLs pass through unchanged.
 * Menu links and redirects store their destination in link fields, so this
 * covers them too.
 */
#[ContentRelayFieldNormalizer(
  id: 'link',
  label: new TranslatableMarkup('Link'),
)]
class Link extends FieldNormalizerBase implements ContainerFactoryPluginInterface {

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
    return $definition->getType() === 'link' || self::itemIs($definition, 'Drupal\link\Plugin\Field\FieldType\LinkItem');
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    if (is_string($values['uri'] ?? NULL)) {
      $values['uri'] = $this->paths->toPortable($values['uri'], $context);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    if (!is_string($item['uri'] ?? NULL)) {
      return $item;
    }
    return LocalPath::resolve($this->paths, $item, 'uri', $definition, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    return is_string($item['uri'] ?? NULL) ? $this->paths->tokens($item['uri']) : [];
  }

}
