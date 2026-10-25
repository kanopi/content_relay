<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Detach, reattach and pull links on synced items.
 */
class EntityUi {

  use StringTranslationTrait;

  public function __construct(
    protected SyncStateStore $syncState,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Operation links, as hook_entity_operation() returns them.
   */
  public function operations(EntityInterface $entity): array {
    if (!$entity instanceof ContentEntityInterface || $entity->isNew()) {
      return [];
    }
    $state = $this->syncState->get($entity->getEntityTypeId(), (string) $entity->uuid());
    if ($state === NULL) {
      return [];
    }
    $operations = [];
    if ($this->currentUser->hasPermission('pull content relay items')) {
      $operations['content_relay_pull_files'] = [
        'title' => $entity->getEntityTypeId() === 'file' ? $this->t('Pull file') : $this->t('Pull missing files'),
        'url' => Url::fromRoute('content_relay_replica.pull_files', [
          'entity_type_id' => $entity->getEntityTypeId(),
          'uuid' => $entity->uuid(),
        ]),
        'weight' => 61,
      ];
    }
    if (!$this->currentUser->hasPermission('detach content relay items')) {
      return $operations;
    }
    $detached = $state['detached_at'] !== NULL;
    return $operations + [
      'content_relay_detach' => [
        'title' => $detached ? $this->t('Reattach') : $this->t('Detach'),
        'url' => Url::fromRoute($detached ? 'content_relay_replica.reattach' : 'content_relay_replica.detach', [
          'entity_type_id' => $entity->getEntityTypeId(),
          'uuid' => $entity->uuid(),
        ]),
        'weight' => 60,
      ],
    ];
  }

}
