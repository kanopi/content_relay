<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\Action;

use Drupal\content_relay_origin\Plugin\Derivative\ItemActions;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Marks items not syncable for every replica, with the configured behavior.
 */
#[Action(
  id: 'content_relay_not_syncable',
  label: new TranslatableMarkup('Mark not syncable'),
  deriver: ItemActions::class,
)]
final class MarkNotSyncable extends NotSyncableActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof ContentEntityInterface) {
      $this->control->markNotSyncable($entity);
    }
  }

}
