<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay_origin\BulkPush;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\content_relay_origin\Report\ReportRebuild;
use Drupal\content_relay_origin\Report\ReportViews;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a bulk resync of 10,000 items and a report of 100,000.
 *
 * Set CONTENT_RELAY_SCALE to a fraction, such as 0.1, to try smaller runs.
 *
 * @group content_relay
 * @group content_relay_slow
 */
#[Group('content_relay')]
#[Group('content_relay_slow')]
#[RunTestsInSeparateProcesses]
final class ScaleTest extends OriginTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * A count, scaled by CONTENT_RELAY_SCALE.
   */
  private static function scaled(int $count): int {
    return max(1, (int) round($count * (float) (getenv('CONTENT_RELAY_SCALE') ?: 1)));
  }

  /**
   * Bulk resync of 10,000 items, in batches, until nothing is out of sync.
   *
   * Scenario 64. Each batch step stands for one request of the Batch API: it
   * has to stay well inside a request's time and memory, whatever the total.
   */
  public function testBulkResync(): void {
    $count = self::scaled(10000);
    // Content made before the replica paired, as on a site adopting sync.
    $transaction = $this->container->get('database')->startTransaction();
    for ($i = 1; $i <= $count; $i++) {
      Node::create(['type' => 'article', 'title' => "Article $i", 'uid' => 0])->save();
      if ($i % 500 === 0) {
        $this->container->get('entity.memory_cache')->deleteAll();
      }
    }
    unset($transaction);
    $this->pairAndAcknowledge();
    $this->container->get('content_relay_origin.report_rebuild')->rebuildIfChanged();
    $this->drainQueue(ReportRebuild::QUEUE);

    // "Push all matching filters" on the article bundle.
    $this->setUpCurrentUser([], ['view content relay status report', 'bulk push content relay items']);
    $ids = $this->container->get('content_relay_origin.report_views')->rowIds(ReportViews::DEFAULT_VIEW, ReportViews::DEFAULT_DISPLAY, ['bundle' => 'article']);
    $this->assertCount($count, $ids);
    $bulk = $this->container->get('content_relay_origin.bulk_push');
    $batch = $bulk->batch($bulk->plan($ids)['items']);
    $this->assertCount((int) ceil($count / BulkPush::BATCH_SIZE), $batch['operations']);

    $context = ['results' => []];
    $slowest = $largest = 0;
    foreach ($batch['operations'] as [$callback, $arguments]) {
      memory_reset_peak_usage();
      $baseline = memory_get_usage();
      $start = microtime(TRUE);
      call_user_func_array($callback, [$arguments[0], &$context]);
      $slowest = max($slowest, microtime(TRUE) - $start);
      $largest = max($largest, memory_get_peak_usage() - $baseline);
      // The next step is another request.
      $this->container->get('entity.memory_cache')->deleteAll();
    }
    BulkPush::batchFinished(TRUE, $context['results'], []);
    $this->assertSame(["Fake replica: $count queued."], array_map('strval', $this->container->get('messenger')->messagesByType('status')));
    $this->assertLessThan(5, $slowest, sprintf('The slowest batch step took %.2f s.', $slowest));
    $this->assertLessThan(16 * 1024 * 1024, $largest, sprintf('A batch step grew memory by up to %.1f MB.', $largest / 1048576));

    // Delivery, a batch at a time as the queue runs it.
    $delivery = $this->container->get('content_relay_origin.delivery');
    $received = 0;
    for ($run = 0; $run < $count && $delivery->deliver($this->replica->siteId); $run++) {
      $received += count($this->replica->events);
      $this->replica->events = [];
      $this->container->get('entity.memory_cache')->deleteAll();
    }
    $received += count($this->replica->events);
    $this->assertSame($count, $received);

    $out_of_sync = $this->container->get('content_relay_origin.report_views')->rowIds(ReportViews::DEFAULT_VIEW, ReportViews::DEFAULT_DISPLAY, ['any_out_of_sync' => 1]);
    $this->assertSame([], $out_of_sync);
  }

  /**
   * A report of 100,000 items loads and filters quickly, using indexes.
   *
   * Scenario 66. Rows are written straight to the report table, as a refresh
   * of a large site would leave them.
   */
  public function testLargeReport(): void {
    $this->pairAndAcknowledge(NULL, ['node' => ['article', 'page']]);
    $replica = $this->replica->siteId;
    $count = self::scaled(100000);
    $statuses = array_merge(array_fill(0, 90, ReportIndex::IN_SYNC), array_fill(0, 5, ReportIndex::OUT_OF_SYNC), [
      ReportIndex::PENDING,
      ReportIndex::PENDING,
      ReportIndex::FAILED,
      ReportIndex::NEVER_SENT,
      ReportIndex::NOT_SYNCABLE,
    ]);
    $uuid = new PhpUuid();
    $now = time();
    $database = $this->container->get('database');
    $fields = [
      'entity_type', 'entity_id', 'uuid', 'bundle', 'langcode', 'default_translation', 'label', 'replica_id',
      'origin_revision', 'origin_hash', 'replica_hash', 'status', 'translation_status', 'item_out_of_sync', 'changed',
    ];
    $insert = NULL;
    for ($i = 1; $i <= $count; $i++) {
      $insert ??= $database->insert(ReportIndex::TABLE)->fields($fields);
      $status = $statuses[$i % 100];
      $item = $uuid->generate();
      // A tenth of the items have a French translation too.
      foreach ($i % 10 === 0 ? ['en', 'fr'] : ['en'] as $langcode) {
        $insert->values([
          'node', (string) $i, $item, $i % 4 === 0 ? 'page' : 'article', $langcode, (int) ($langcode === 'en'), "Item $i ($langcode)", $replica,
          $i, hash('sha256', "o$i"), $status === ReportIndex::IN_SYNC ? hash('sha256', "o$i") : NULL, $status,
          ReportIndex::TRANSLATION_PRESENT, (int) !in_array($status, [ReportIndex::IN_SYNC, ReportIndex::NOT_SYNCABLE], TRUE), $now - $i * 300,
        ]);
      }
      if ($i % 1000 === 0 || $i === $count) {
        $insert->execute();
        $insert = NULL;
      }
    }
    if ($database->databaseType() === 'mysql') {
      $database->query('ANALYZE TABLE {' . ReportIndex::TABLE . '}');
    }
    $rows = (int) $database->query('SELECT COUNT(*) FROM {' . ReportIndex::TABLE . '}')->fetchField();
    $this->assertSame($count + intdiv($count, 10), $rows);

    $this->setUpCurrentUser([], ['view content relay status report', 'bulk push content relay items']);
    $renderer = $this->container->get('renderer');
    $filter_sets = [
      'everything' => [],
      'a status' => ['status' => [ReportIndex::OUT_OF_SYNC]],
      'any out of sync' => ['any_out_of_sync' => 1],
      'a bundle' => ['bundle' => 'page'],
      'a language' => ['langcode' => 'fr'],
      'the replica' => ['replica' => $replica],
      'a date range' => ['changed' => ['min' => date('Y-m-d', $now - 86400 * 30), 'max' => date('Y-m-d', $now)]],
      'a search' => ['search' => 'Item 4242 '],
      'combined' => [
        'status' => [ReportIndex::OUT_OF_SYNC],
        'bundle' => 'article',
        'langcode' => 'en',
        'replica' => $replica,
      ],
      'a deep page' => ['page' => (string) intdiv($count, 100)],
    ];
    // What some filters should match, counted directly.
    $expected = [
      'a status' => ['status', ReportIndex::OUT_OF_SYNC],
      'any out of sync' => ['item_out_of_sync', 1],
      'a bundle' => ['bundle', 'page'],
      'a language' => ['langcode', 'fr'],
    ];
    $mysql = $database->databaseType() === 'mysql';
    foreach ($filter_sets as $name => $filters) {
      $start = microtime(TRUE);
      $view = Views::getView(ReportViews::DEFAULT_VIEW);
      $view->setDisplay(ReportViews::DEFAULT_DISPLAY);
      if (isset($filters['page'])) {
        $view->setCurrentPage((int) $filters['page']);
        unset($filters['page']);
      }
      $view->setExposedInput($filters);
      $build = $view->preview();
      $html = (string) $renderer->renderInIsolation($build);
      $elapsed = microtime(TRUE) - $start;
      $this->assertLessThan(2.0, $elapsed, sprintf('The report with %s took %.2f s.', $name, $elapsed));
      $this->assertNotSame([], $view->result, "The report with $name shows rows.");
      if (isset($expected[$name])) {
        $matching = $database->select(ReportIndex::TABLE)->condition(...$expected[$name])->countQuery()->execute()->fetchField();
        $this->assertSame((int) $matching, (int) $view->total_rows, "The report with $name counts what matches.");
      }
      $this->assertStringContainsString('Item ', $html);

      if ($mysql) {
        // A first page is read in order from an index, never by sorting the
        // whole table; deep pages may be.
        if ($name !== 'a deep page') {
          $this->assertIndexed($view->build_info['query'], $name, FALSE);
        }
        // Counting uses an index too, except to search labels: "contains"
        // reads every one.
        if ($name !== 'a search') {
          $this->assertIndexed((clone $view->build_info['count_query'])->countQuery(), "$name, counting", TRUE);
        }
      }
    }
  }

  /**
   * Checks MySQL reads the report table through an index.
   *
   * @param object $query
   *   The query.
   * @param string $name
   *   What it is, for messages.
   * @param bool $sort
   *   Whether sorting is allowed: only when nothing is ordered anyway.
   */
  private function assertIndexed(object $query, string $name, bool $sort): void {
    $plan = $this->container->get('database')->query('EXPLAIN ' . (string) $query, $query->getArguments())->fetchAll();
    foreach ($plan as $step) {
      if ($step->table === ReportIndex::TABLE) {
        $this->assertNotSame('ALL', $step->type, sprintf('The report with %s scans the whole table: %s', $name, json_encode($plan)));
        $this->assertNotNull($step->key, sprintf('The report with %s uses no index: %s', $name, json_encode($plan)));
        if (!$sort) {
          $this->assertStringNotContainsString('filesort', (string) $step->Extra, sprintf('The report with %s sorts every row: %s', $name, json_encode($plan)));
        }
      }
    }
  }

  /**
   * Runs a queue until it is empty.
   */
  private function drainQueue(string $name): void {
    $queue = $this->container->get('queue')->get($name);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance($name);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

}
