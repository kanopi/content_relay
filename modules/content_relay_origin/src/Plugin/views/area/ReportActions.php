<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\views\area;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * Links to push everything the filters match, refresh, and export as CSV.
 *
 * The links carry the current filters, so each acts on what the report
 * shows, across every page.
 */
#[ViewsArea('content_relay_report_actions')]
final class ReportActions extends AreaPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE): array {
    // Empty and "any" values add nothing to the query string.
    $empty = ['', 'All', NULL, []];
    $query = array_filter((array) $this->view->getExposedInput(), fn (mixed $value) => !in_array($value, $empty, TRUE));
    unset($query['page'], $query['order'], $query['sort']);
    $source = ['view' => $this->view->id(), 'display' => $this->view->current_display];
    $links = [
      'push' => Link::fromTextAndUrl($this->t('Push all matching filters'), Url::fromRoute('content_relay_origin.bulk_push', [], ['query' => $source + ['filters' => $query]])),
      'refresh' => Link::fromTextAndUrl($this->t('Refresh status'), Url::fromRoute('content_relay_origin.report_refresh')),
      'export' => Link::fromTextAndUrl($this->t('Download CSV'), Url::fromRoute('content_relay_origin.report_export', [], ['query' => $source + ['filters' => $query]])),
    ];
    $items = [];
    foreach ($links as $key => $link) {
      if ($link->getUrl()->access($this->view->getUser())) {
        $items[$key] = $link->toRenderable() + ['#attributes' => ['class' => ['button', 'button--small']]];
      }
    }
    if ($items === []) {
      return [];
    }
    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['content-relay-report-actions', 'inline']],
      '#cache' => ['contexts' => ['url.query_args', 'user.permissions']],
    ];
  }

}
