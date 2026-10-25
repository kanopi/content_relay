<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\content_relay_origin\Report\ReportViews;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shipped status report view, its filters and the CSV export.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReportViewTest extends OriginTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->pairAndAcknowledge(NULL, ['node' => ['article', 'page']]);
  }

  /**
   * Labels the view shows for filters.
   */
  private function labels(array $filters): array {
    $view = Views::getView(ReportViews::DEFAULT_VIEW);
    $view->setDisplay(ReportViews::DEFAULT_DISPLAY);
    $view->setExposedInput($filters);
    $view->execute();
    $labels = [];
    foreach ($view->result as $row) {
      $labels[] = $view->field['label']->getValue($row);
    }
    sort($labels);
    return $labels;
  }

  /**
   * Filters return the right rows, alone and combined.
   */
  public function testFilters(): void {
    $synced = $this->node('article', 'Synced article');
    $this->deliver();
    $this->node('article', 'Pending article');
    $page = $this->node('page', 'Pending page');
    $this->container->get('content_relay_origin.item_sync_control')->markNotSyncable($page);

    $this->assertSame(['Pending article', 'Pending page', 'Synced article'], $this->labels([]));
    $this->assertSame(['Synced article'], $this->labels(['status' => [ReportIndex::IN_SYNC]]));
    $this->assertSame(['Pending article'], $this->labels(['status' => [ReportIndex::PENDING]]));
    $this->assertSame(['Pending page'], $this->labels(['status' => [ReportIndex::NOT_SYNCABLE]]));
    $this->assertSame(['Pending article', 'Pending page'], $this->labels(['any_out_of_sync' => 1]));
    $this->assertSame(['Pending article'], $this->labels(['any_out_of_sync' => 1, 'bundle' => 'article']));
    $this->assertSame([], $this->labels(['status' => [ReportIndex::IN_SYNC], 'bundle' => 'page']));
    $this->assertSame(['Pending article', 'Synced article'], $this->labels([
      'replica' => $this->replica->siteId,
      'bundle' => 'article',
    ]));
    $this->assertSame(['Synced article'], $this->labels(['search' => 'Synced']));
    $this->assertSame(['Synced article'], $this->labels(['search' => $synced->uuid()]));
    $this->assertSame([], $this->labels(['replica' => 'nobody']));
  }

  /**
   * Detached and failed rows, and the date range, filter as the others do.
   */
  public function testMoreFilters(): void {
    $old = Node::create(['type' => 'article', 'title' => 'Old article', 'uid' => 0, 'changed' => 1600000000]);
    $old->save();
    $detached = $this->node('article', 'Detached article');
    $this->deliver();
    $this->container->get('content_relay_origin.item_sync_control')->replicaDetached($this->replica->siteId, 'node', $detached->uuid(), 'article');
    // Refused for good, then refused for now.
    $this->replica->eventResponses[] = [422, ['result' => 'failed', 'code' => 'save_failed']];
    $this->node('page', 'Refused page');
    $this->deliver();
    $this->replica->eventResponses[] = [503, ['result' => 'busy']];
    $this->node('page', 'Busy page');
    $this->deliver();

    $this->assertSame(['Detached article'], $this->labels(['status' => [ReportIndex::DETACHED_BY_REPLICA]]));
    $this->assertSame(['Refused page'], $this->labels(['status' => [ReportIndex::DEAD_LETTERED]]));
    $this->assertSame(['Busy page'], $this->labels(['status' => [ReportIndex::FAILED]]));
    $this->assertSame(['Busy page', 'Refused page'], $this->labels(['any_out_of_sync' => 1]));
    $this->assertSame(['Old article'], $this->labels(['changed' => ['min' => '2020-09-01', 'max' => '2020-09-30']]));
    $this->assertSame([], $this->labels([
      'changed' => ['min' => '2020-09-01', 'max' => '2020-09-30'],
      'bundle' => 'page',
    ]));
    $this->assertSame(['Busy page', 'Detached article', 'Old article', 'Refused page'], $this->labels(['langcode' => 'en']));
  }

  /**
   * Push all matching filters covers every page; CSV matches the filters.
   */
  public function testAllMatchingAndCsv(): void {
    for ($i = 1; $i <= 60; $i++) {
      $this->node('article', "Article $i");
    }
    $this->node('page', 'A page');
    $this->setUpCurrentUser([], ['view content relay status report']);
    $views = $this->container->get('content_relay_origin.report_views');
    $ids = $views->rowIds(ReportViews::DEFAULT_VIEW, ReportViews::DEFAULT_DISPLAY, [
      'bundle' => 'article',
      'status' => [ReportIndex::PENDING],
    ]);
    $this->assertCount(60, $ids, 'Beyond the first page of 50.');

    $plan = $this->container->get('content_relay_origin.bulk_push')->plan($ids);
    $this->assertCount(60, $plan['items']);

    $csv = $views->csv(ReportViews::DEFAULT_VIEW, ReportViews::DEFAULT_DISPLAY, ['bundle' => 'page']);
    $lines = array_values(array_filter(explode("\n", $csv)));
    $this->assertCount(2, $lines);
    $header = str_getcsv($lines[0], escape: '');
    $this->assertContains('Label', $header);
    $this->assertNotContains('', $header, 'The bulk form column is left out.');
    $row = array_combine($header, str_getcsv($lines[1], escape: ''));
    $this->assertSame('A page', $row['Label']);
    $this->assertSame('Pending', $row['Status']);
    $this->assertSame('Fake replica', $row['Replica']);
  }

}
