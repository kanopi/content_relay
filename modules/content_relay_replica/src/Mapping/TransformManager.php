<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers transform plugins.
 *
 * @method \Drupal\content_relay_replica\Mapping\TransformInterface createInstance($plugin_id, array $configuration = [])
 */
class TransformManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/ContentRelay/Transform',
      $namespaces,
      $module_handler,
      TransformInterface::class,
      ContentRelayTransform::class,
    );
    $this->alterInfo('content_relay_transform_info');
    $this->setCacheBackend($cache_backend, 'content_relay_transform_plugins');
  }

}
