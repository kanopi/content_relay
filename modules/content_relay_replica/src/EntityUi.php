<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Detach and reattach links on synced items.
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
    if (!$entity instanceof ContentEntityInterface || $entity->isNew() || !$this->currentUser->hasPermission('detach content relay items')) {
      return [];
    }
    $state = $this->syncState->get($entity->getEntityTypeId(), (string) $entity->uuid());
    if ($state === NULL) {
      return [];
    }
    $detached = $state['detached_at'] !== NULL;
    return [
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
