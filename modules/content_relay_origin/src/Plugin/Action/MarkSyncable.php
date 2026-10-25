<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\Action;

use Drupal\content_relay_origin\Plugin\Derivative\ItemActions;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Removes every not syncable flag from items.
 */
#[Action(
  id: 'content_relay_syncable',
  label: new TranslatableMarkup('Make syncable'),
  deriver: ItemActions::class,
)]
final class MarkSyncable extends NotSyncableActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if ($entity instanceof ContentEntityInterface) {
      $this->control->markSyncable($entity);
    }
  }

}
