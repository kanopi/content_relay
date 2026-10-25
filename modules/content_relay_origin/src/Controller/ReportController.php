<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay_origin\Report\ReportRebuild;
use Drupal\content_relay_origin\Report\ReportViews;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refreshes the status report and exports it.
 */
final class ReportController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ReportRebuild $rebuild,
    private readonly ReportViews $reportViews,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.report_rebuild'),
      $container->get('content_relay_origin.report_views'),
    );
  }

  /**
   * Recomputes every row through the Batch API.
   */
  public function refresh(): mixed {
    batch_set($this->rebuild->batch());
    return batch_process(Url::fromRoute('view.' . ReportViews::DEFAULT_VIEW . '.' . ReportViews::DEFAULT_DISPLAY));
  }

  /**
   * The report as CSV, with the filters in the query string.
   */
  public function export(Request $request): Response {
    $filters = $request->query->all()['filters'] ?? [];
    $csv = $this->reportViews->csv(
      (string) $request->query->get('view', ReportViews::DEFAULT_VIEW),
      (string) $request->query->get('display', ReportViews::DEFAULT_DISPLAY),
      is_array($filters) ? $filters : [],
    );
    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="content-relay-status.csv"',
      'Cache-Control' => 'private, no-store',
    ]);
  }

}
