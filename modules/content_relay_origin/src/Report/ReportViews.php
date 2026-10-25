<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Report;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runs a status report view with given filters, beyond its pager.
 *
 * Bulk push of everything matching the filters and the CSV export both work
 * on the view as the site builder configured it, with the filters the user
 * had set, so they act on exactly what the report shows on all its pages.
 */
class ReportViews {

  public const DEFAULT_VIEW = 'content_relay_origin_report';
  public const DEFAULT_DISPLAY = 'page_1';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ViewExecutableFactory $viewFactory,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Loads a report view display with filters applied.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the view is not a status report view.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the user may not see the display.
   */
  public function load(string $view_id, string $display_id, array $filters): ViewExecutable {
    /** @var \Drupal\views\ViewEntityInterface|null $config */
    $config = $this->entityTypeManager->getStorage('view')->load($view_id);
    if ($config === NULL || $config->get('base_table') !== ReportIndex::TABLE) {
      throw new NotFoundHttpException();
    }
    $view = $this->viewFactory->get($config);
    if (!$view->setDisplay($display_id) || !$view->access($display_id, $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }
    $view->setExposedInput($filters);
    $view->setItemsPerPage(0);
    $view->setOffset(0);
    return $view;
  }

  /**
   * The report row IDs a view matches with filters, on every page.
   *
   * @return int[]
   *   Row IDs.
   */
  public function rowIds(string $view_id, string $display_id, array $filters): array {
    $view = $this->load($view_id, $display_id, $filters);
    $view->build();
    if (empty($view->build_info['query'])) {
      return [];
    }
    /** @var \Drupal\Core\Database\Query\SelectInterface $query */
    $query = clone $view->build_info['query'];
    $fields = &$query->getFields();
    $fields = [];
    $expressions = &$query->getExpressions();
    $expressions = [];
    $order = &$query->getOrderBy();
    $order = [];
    $query->range();
    $query->addField(ReportIndex::TABLE, 'id', 'content_relay_row_id');
    $query->distinct();
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * The rows of a view with filters, as CSV.
   *
   * Columns are the view's visible fields, with their labels, rendered as
   * plain text.
   */
  public function csv(string $view_id, string $display_id, array $filters): string {
    $view = $this->load($view_id, $display_id, $filters);
    $view->execute();
    // What rendering the view would do first: labels for coded values.
    foreach ($view->field as $field) {
      $field->preRender($view->result);
    }
    $columns = [];
    foreach ($view->field as $id => $field) {
      if (!empty($field->options['exclude']) || $field->getPluginId() === 'content_relay_bulk_push') {
        continue;
      }
      $columns[$id] = (string) ($field->options['label'] ?: $field->adminLabel());
    }
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, array_values($columns), escape: '');
    foreach (array_keys($view->result) as $index) {
      $line = [];
      foreach (array_keys($columns) as $id) {
        $value = (string) $view->style_plugin->getField($index, $id);
        $line[] = html_entity_decode(trim(strip_tags($value)), ENT_QUOTES | ENT_HTML5);
      }
      fputcsv($handle, $line, escape: '');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
  }

}
