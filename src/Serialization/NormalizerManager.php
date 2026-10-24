<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Finds the normalizer for each entity type.
 *
 * Third-party modules add normalizers for their own entity types by placing a
 * plugin in src/Plugin/ContentRelay/Normalizer; see the attribute class.
 */
class NormalizerManager extends DefaultPluginManager {

  /**
   * Normalizer instances, by entity type ID.
   *
   * @var array<string, \Drupal\content_relay\Serialization\EntityNormalizerInterface>
   */
  protected array $byEntityType = [];

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct(
      'Plugin/ContentRelay/Normalizer',
      $namespaces,
      $module_handler,
      EntityNormalizerInterface::class,
      ContentRelayNormalizer::class,
    );
    $this->alterInfo('content_relay_normalizer_info');
    $this->setCacheBackend($cache_backend, 'content_relay_normalizer_plugins');
  }

  /**
   * The normalizer for an entity type.
   *
   * @throws \InvalidArgumentException
   *   When the entity type cannot be synced or no plugin applies.
   */
  public function getNormalizer(string $entity_type_id): EntityNormalizerInterface {
    if (isset($this->byEntityType[$entity_type_id])) {
      return $this->byEntityType[$entity_type_id];
    }
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$entity_type instanceof ContentEntityTypeInterface || !$entity_type->hasKey('uuid')) {
      throw new \InvalidArgumentException(sprintf('Entity type "%s" is not a content entity type with a UUID and cannot be synced.', $entity_type_id));
    }

    $definitions = array_filter(
      $this->getDefinitions(),
      fn (array $definition): bool => in_array($entity_type_id, $definition['entity_types'], TRUE) || in_array('*', $definition['entity_types'], TRUE),
    );
    // Highest weight first; an explicit entity type beats a wildcard at equal
    // weight; then plugin ID, so the choice never depends on discovery order.
    $rank = fn (array $definition): array => [
      $definition['weight'],
      in_array($entity_type_id, $definition['entity_types'], TRUE),
    ];
    uasort($definitions, fn (array $a, array $b): int => [...$rank($b), $a['id']] <=> [...$rank($a), $b['id']]);

    foreach ($definitions as $plugin_id => $definition) {
      $normalizer = $this->createInstance($plugin_id);
      assert($normalizer instanceof EntityNormalizerInterface);
      if ($normalizer->applies($entity_type)) {
        return $this->byEntityType[$entity_type_id] = $normalizer;
      }
    }
    throw new \InvalidArgumentException(sprintf('No normalizer applies to entity type "%s".', $entity_type_id));
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    parent::clearCachedDefinitions();
    $this->byEntityType = [];
  }

}
