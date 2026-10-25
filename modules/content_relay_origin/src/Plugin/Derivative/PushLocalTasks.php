<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_origin\Routing\ItemRoutes;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A "Content Relay" tab on the page of every entity type that can be synced.
 */
final class PushLocalTasks extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): self {
    return new self($container->get('content_relay.syncable_entity_types'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $this->derivatives = [];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $definition) {
      if (!$definition->hasLinkTemplate('canonical')) {
        continue;
      }
      $this->derivatives[$entity_type_id] = [
        'route_name' => ItemRoutes::name($entity_type_id, 'push'),
        'base_route' => "entity.$entity_type_id.canonical",
        'title' => $this->t('Content Relay'),
        'weight' => 50,
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
