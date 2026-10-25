<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\views\field;

use Drupal\content_relay_origin\EventStatus;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Links on an event log row: its details, and replay when it failed.
 */
#[ViewsField('content_relay_event_operations')]
final class EventOperations extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['status']);
    parent::query();
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface {
    $id = (int) $this->getValue($values);
    $links = [
      'view' => [
        'title' => $this->t('Details'),
        'url' => Url::fromRoute('content_relay_origin.event', ['row_id' => $id]),
      ],
    ];
    $status = $values->{$this->aliases['status']} ?? NULL;
    if (in_array($status, EventStatus::REPLAYABLE, TRUE) && Url::fromRoute('content_relay_origin.event_replay', ['row_id' => $id])->access()) {
      $links['replay'] = [
        'title' => $this->t('Replay'),
        'url' => Url::fromRoute('content_relay_origin.event_replay', ['row_id' => $id]),
      ];
    }
    $build = ['#type' => 'operations', '#links' => $links];
    return $this->getRenderer()->render($build);
  }

}
