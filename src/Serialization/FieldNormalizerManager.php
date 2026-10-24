<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Finds the field normalizer for each field, on any entity type.
 */
class FieldNormalizerManager extends DefaultPluginManager {

  /**
   * Instances, by plugin ID.
   *
   * @var array<string, \Drupal\content_relay\Serialization\FieldNormalizerInterface>
   */
  protected array $instances = [];

  /**
   * Chosen plugin IDs by field, FALSE for none.
   *
   * @var array<string, string|false>
   */
  protected array $byField = [];

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/ContentRelay/FieldNormalizer',
      $namespaces,
      $module_handler,
      FieldNormalizerInterface::class,
      ContentRelayFieldNormalizer::class,
    );
    $this->alterInfo('content_relay_field_normalizer_info');
    $this->setCacheBackend($cache_backend, 'content_relay_field_normalizer_plugins');
  }

  /**
   * The normalizer for a field, or NULL to sync its values as they are.
   */
  public function getForField(FieldDefinitionInterface $definition): ?FieldNormalizerInterface {
    // Bundle matters: a field's settings, such as a reference's target type,
    // can differ per bundle.
    $key = $definition->getTargetEntityTypeId() . '.' . $definition->getTargetBundle() . '.' . $definition->getName() . '.' . $definition->getType();
    if (!array_key_exists($key, $this->byField)) {
      $this->byField[$key] = FALSE;
      $definitions = $this->getDefinitions();
      uasort($definitions, fn (array $a, array $b): int => [$b['weight'], $a['id']] <=> [$a['weight'], $b['id']]);
      foreach (array_keys($definitions) as $plugin_id) {
        if ($this->instance($plugin_id)->applies($definition)) {
          $this->byField[$key] = $plugin_id;
          break;
        }
      }
    }
    return $this->byField[$key] === FALSE ? NULL : $this->instance($this->byField[$key]);
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    parent::clearCachedDefinitions();
    $this->instances = $this->byField = [];
  }

  /**
   * A plugin instance, created once.
   */
  protected function instance(string $plugin_id): FieldNormalizerInterface {
    if (!isset($this->instances[$plugin_id])) {
      $instance = $this->createInstance($plugin_id);
      assert($instance instanceof FieldNormalizerInterface);
      $this->instances[$plugin_id] = $instance;
    }
    return $this->instances[$plugin_id];
  }

}
