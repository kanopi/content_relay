<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Lists mappings.
 */
class MappingListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Mapping'),
      'source' => $this->t('From the origin'),
      'target' => $this->t('To'),
      'status' => $this->t('Enabled'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\content_relay_replica\Entity\ContentRelayMapping $entity */
    return [
      'label' => $entity->label(),
      'source' => $entity->getSourceEntityType() . ' › ' . $entity->getSourceBundle(),
      'target' => $entity->getTargetEntityType() . ' › ' . $entity->getTargetBundle(),
      'status' => $entity->status() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $operations = parent::getDefaultOperations($entity, ...array_filter([$cacheability]));
    $operations['reapply'] = [
      'title' => $this->t('Re-apply to synced items'),
      'url' => Url::fromRoute('content_relay_replica.mapping_reapply', ['content_relay_mapping' => $entity->id()]),
      'weight' => 20,
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Origin bundles without a mapping are taken as they are, onto the same bundle here. A mapping puts an origin bundle onto a different structure.') . '</p>',
    ];
    return $build + parent::render();
  }

}
