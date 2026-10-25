<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\content_relay\TestClock;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests outages of either site, run through cron on a clock moved forward.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class OutageTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_translation', 'file', 'language'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('datetime.time')->setClass(TestClock::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    TestClock::reset();
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_file', 'type' => 'file'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_file'])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->pairAndAcknowledge();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    TestClock::reset();
    parent::tearDown();
  }

  /**
   * Runs cron, as the site's cron job would.
   */
  private function cron(): void {
    $this->container->get('cron')->run();
  }

  /**
   * Event log counts for the replica, by status.
   */
  private function counts(): array {
    return $this->container->get('content_relay_origin.outbox')->countsByStatus($this->replica->siteId);
  }

  /**
   * Checks the replica received its stream in order.
   *
   * Each item's events arrive in sequence order, and so do the articles'.
   * Files are left out of the second check: what an item references is sent
   * just ahead of it, whatever its sequence.
   *
   * @param bool $chain
   *   Also check that each article event names the one before it, which holds
   *   when nothing was sent ahead or set aside.
   */
  private function assertInOrder(bool $chain): void {
    $items = [];
    $previous = NULL;
    foreach ($this->replica->events as $event) {
      $key = $event['entity_type'] . ':' . $event['entity_uuid'];
      if (isset($items[$key])) {
        $this->assertGreaterThan($items[$key], $event['sequence']);
      }
      $items[$key] = $event['sequence'];
      if ($event['entity_type'] !== 'node') {
        continue;
      }
      if ($previous !== NULL) {
        $this->assertGreaterThan($previous, $event['sequence']);
        if ($chain) {
          $this->assertSame($previous, $event['previous_sequence'], 'Each event names the one before it.');
        }
      }
      $previous = $event['sequence'];
    }
  }

  /**
   * Checks the replica's last version of each item is the origin's current.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   The items, by ID; deleted ones as they were.
   * @param int[] $deleted
   *   IDs of the deleted items.
   */
  private function assertReplicaMatches(array $nodes, array $deleted): void {
    $last = [];
    foreach ($this->replica->events as $event) {
      $last[$event['entity_uuid']] = $event;
    }
    $report = $this->container->get('content_relay_origin.report');
    foreach ($nodes as $id => $node) {
      if (in_array($id, $deleted, TRUE)) {
        $this->assertSame('delete', $last[$node->uuid()]['operation'] ?? NULL, "Item $id was deleted.");
        continue;
      }
      $current = Node::load($id);
      $event = $last[$node->uuid()];
      $this->assertSame((int) $current->getRevisionId(), $event['source_revision'], "Item $id is at the current revision.");
      $this->assertSame($current->label(), $event['snapshot']['translations']['en']['title'][0]['value']);
      if ($current->hasTranslation('fr')) {
        $this->assertSame($current->getTranslation('fr')->label(), $event['snapshot']['translations']['fr']['title'][0]['value']);
      }
      $report->refresh('node', $current->uuid());
      $statuses = $this->container->get('database')->select(ReportIndex::TABLE, 'r')
        ->fields('r', ['status'])
        ->condition('uuid', $current->uuid())
        ->execute()
        ->fetchCol();
      $this->assertSame([ReportIndex::IN_SYNC], array_values(array_unique($statuses)), "Item $id is in sync in the report.");
    }
  }

  /**
   * The origin's cron stops, the site goes down, and comes back.
   *
   * Scenario 20: what was saved before the outage is delivered once the site
   * is back, in order, with nothing left queued or failed.
   */
  public function testOriginOutageAndRecovery(): void {
    // Cron has stopped: about 20 creates, edits and deletes queue up.
    $nodes = [];
    for ($i = 1; $i <= 10; $i++) {
      $node = $this->node('article', "Article $i");
      $nodes[(int) $node->id()] = $node;
    }
    foreach (array_slice($nodes, 0, 6, TRUE) as $node) {
      $node->setTitle($node->label() . ', edited')->save();
    }
    $deleted = array_slice(array_keys($nodes), 6, 4);
    foreach ($deleted as $id) {
      Node::load($id)->delete();
    }
    $this->assertSame([], $this->replica->events);
    $this->assertSame(20, $this->counts()[EventStatus::QUEUED]);

    // Down for ten minutes, then a fresh process with cron running again.
    TestClock::advance(600);
    $this->container->get('kernel')->rebuildContainer();
    $container = \Drupal::getContainer();
    assert($container instanceof ContainerBuilder);
    $this->container = $container;
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($this->replica)]));
    $this->cron();

    $this->assertNotEmpty($this->replica->events);
    $this->assertInOrder(TRUE);
    $counts = $this->counts();
    $this->assertArrayNotHasKey(EventStatus::QUEUED, $counts);
    $this->assertArrayNotHasKey(EventStatus::FAILED, $counts);
    $this->assertArrayNotHasKey(EventStatus::DEAD_LETTER, $counts);
    $this->assertReplicaMatches($nodes, $deleted);
  }

  /**
   * The replica is down for hours while the origin keeps changing content.
   *
   * Scenario 21: saves never wait on the replica; the first event retries
   * with growing delays while the rest wait behind it; once the replica is
   * back, the backlog drains in order, dead letters are replayed, and the
   * report has nothing out of sync.
   */
  public function testReplicaDownForHours(): void {
    $directory = 'public://';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $outbox = $this->container->get('content_relay_origin.outbox');
    $this->replica->down = TRUE;

    // 200 changes, one a minute: creates (some with a translation or a
    // file), repeated edits of the same items, translations and deletes.
    $nodes = [];
    $deleted = [];
    $delays = [];
    $due = [];
    for ($i = 0; $i < 200; $i++) {
      TestClock::advance(60);
      $sent = count($this->replica->envelopes);
      $live = array_values(array_diff(array_keys($nodes), $deleted));
      if ($i < 40) {
        $values = ['type' => 'article', 'title' => "Article $i", 'uid' => 0, 'langcode' => 'en'];
        if ($i % 7 === 0) {
          file_put_contents("public://document-$i.txt", "Document $i");
          $file = File::create(['uri' => "public://document-$i.txt", 'filename' => "document-$i.txt", 'status' => 1]);
          $file->save();
          $values['field_file'] = ['target_id' => $file->id(), 'display' => 1];
        }
        $node = Node::create($values);
        if ($i % 5 === 0) {
          $node->addTranslation('fr', ['title' => "Article $i en français"]);
        }
        $node->save();
        $nodes[(int) $node->id()] = $node;
      }
      elseif ($i % 20 === 4) {
        $id = $live[$i % count($live)];
        Node::load($id)->delete();
        $deleted[] = $id;
      }
      else {
        // A few items are edited over and over.
        $node = Node::load($i % 3 === 0 ? $live[0] : $live[$i % count($live)]);
        if ($i % 5 === 2) {
          $node->hasTranslation('fr')
            ? $node->getTranslation('fr')->setTitle("Traduction $i")
            : $node->addTranslation('fr', ['title' => "Traduction $i"]);
        }
        else {
          $node->setTitle("Edit $i");
        }
        $node->save();
      }
      $this->assertCount($sent, $this->replica->envelopes, 'Saving sends nothing.');

      // Cron runs every five minutes.
      if ($i % 5 === 4) {
        $this->cron();
        // At most one event is retried at a time, the oldest or something it
        // needs sent first; nothing behind it is tried.
        $retrying = $this->container->get('database')->select(Outbox::TABLE, 'o')
          ->fields('o', ['id', 'attempts', 'next_attempt'])
          ->condition('replica_id', $this->replica->siteId)
          ->condition('status', EventStatus::PENDING, 'IN')
          ->condition('attempts', 0, '>')
          ->execute()
          ->fetchAll();
        $this->assertLessThanOrEqual(1, count($retrying));
        if ($retrying !== []) {
          [$id, $attempts] = [$retrying[0]->id, (int) $retrying[0]->attempts];
          // Not retried before it was due.
          if (isset($due[$id]) && $due[$id][1] > TestClock::now()) {
            $this->assertSame($due[$id][0], $attempts);
          }
          $due[$id] = [$attempts, (int) $retrying[0]->next_attempt];
          // The delay set by an attempt this run, seen for the first time.
          $delays[$id][$attempts] ??= (int) $retrying[0]->next_attempt - TestClock::now();
        }
      }
    }
    $this->assertSame([], $this->replica->events);

    // Each retried event waited longer each time, never shorter.
    $this->assertNotEmpty($delays);
    foreach ($delays as $by_attempt) {
      ksort($by_attempt);
      $previous = 0;
      foreach ($by_attempt as $delay) {
        $this->assertGreaterThanOrEqual($previous, $delay);
        $previous = $delay;
      }
    }
    $this->assertGreaterThan(1, max(array_map('count', $delays)));

    // The replica comes back; cron keeps running every five minutes until
    // the backlog is gone.
    $this->replica->down = FALSE;
    for ($run = 0; $run < 100 && $outbox->nextPending($this->replica->siteId) !== NULL; $run++) {
      TestClock::advance(300);
      $this->cron();
    }
    $this->assertNull($outbox->nextPending($this->replica->siteId));

    // Events that ran out of attempts during the outage are replayed.
    $dead = $this->container->get('database')->select(Outbox::TABLE, 'o')
      ->fields('o', ['id'])
      ->condition('replica_id', $this->replica->siteId)
      ->condition('status', EventStatus::DEAD_LETTER)
      ->execute()
      ->fetchCol();
    foreach ($dead as $id) {
      $this->container->get('content_relay_origin.replay')->replay((int) $id);
    }
    $this->cron();

    $this->assertInOrder(FALSE);
    $this->assertLessThan(200, count($this->replica->events), 'Queued edits of the same item were sent once.');
    $counts = $this->counts();
    foreach ([EventStatus::QUEUED, EventStatus::FAILED, EventStatus::DEAD_LETTER] as $status) {
      $this->assertArrayNotHasKey($status, $counts);
    }
    $this->assertReplicaMatches($nodes, $deleted);
  }

}
