<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One not syncable action per entity type that can be synced.
 */
final class ItemActions extends DeriverBase implements ContainerDeriverInterface {

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
      $this->derivatives[$entity_type_id] = [
        'type' => $entity_type_id,
        'label' => sprintf('%s (%s)', $base_plugin_definition['label'], $definition->getLabel()),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
