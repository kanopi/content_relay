<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\ContractStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a site that is a replica of one site and the origin of others.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class BothRolesTest extends ReplicaTestBase {

  /**
   * A replica of this site downstream.
   */
  private const DOWNSTREAM = '7c1a2b3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Installed as a site builder would, on top of the replica: each role's
    // tables, services and routes beside the other's.
    $this->container->get('module_installer')->install(['content_relay_origin']);
    $this->config('content_relay_origin.settings')->set('bundles', ['node' => ['article']])->save();
    $registry = $this->container->get('content_relay_origin.replica_registry');
    $contracts = $this->container->get('content_relay_origin.contract_manager');
    // Downstream, and this site's own origin, which receives from it too.
    foreach ([self::DOWNSTREAM => 'Downstream', self::ORIGIN => 'Upstream'] as $id => $label) {
      $registry->save(new Replica($id, $label, "https://$id.example.com/content-relay/v1/events", Replica::ACTIVE, []));
      $contracts->propose($id, ['bundles' => ['node' => ['article']]], NULL, 'replica');
      $contracts->acknowledge($id, 1);
    }
  }

  /**
   * Outbox rows so far.
   */
  private function lastRow(): int {
    return (int) $this->container->get('database')->query('SELECT MAX(id) FROM {' . Outbox::TABLE . '}')->fetchField();
  }

  /**
   * Replicas an item was recorded for since a row, by operation.
   */
  private function recorded(string $uuid, int $after): array {
    $rows = $this->container->get('database')->select(Outbox::TABLE, 'o')
      ->fields('o', ['operation', 'replica_id'])
      ->condition('entity_uuid', $uuid)
      ->condition('id', $after, '>')
      ->orderBy('id')
      ->execute()
      ->fetchAll();
    $recorded = [];
    foreach ($rows as $row) {
      $recorded[$row->operation][] = $row->replica_id;
    }
    return $recorded;
  }

  /**
   * Each role keeps its own tables, commands and key announcer.
   */
  public function testRolesSideBySide(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists(ContractStore::TABLE));
    $this->assertTrue($schema->tableExists('content_relay_contract'));
    // This site's contract with its origin, and its contracts as an origin.
    $this->assertSame(1, $this->container->get('content_relay_replica.contracts')->getCurrent()->version());
    $this->assertSame(1, $this->container->get('content_relay_origin.contract_manager')->getActive(self::DOWNSTREAM)->version());

    // A new key is announced to the origin and to every replica.
    $rotation = $this->container->get('content_relay.key_rotation');
    $announcers = (new \ReflectionProperty($rotation, 'announcers'))->getValue($rotation);
    $this->assertSame(
      [
        'Drupal\content_relay_origin\Crypto\ReplicaKeyAnnouncer',
        'Drupal\content_relay_replica\Crypto\OriginKeyAnnouncer',
      ],
      array_map('get_class', $announcers),
    );

    // Both reports have a page.
    $routes = $this->container->get('router.route_provider');
    $this->assertSame('/admin/reports/content-relay', $routes->getRouteByName('view.content_relay_origin_report.page_1')->getPath());
    $this->assertSame('/admin/reports/content-relay/received', $routes->getRouteByName('view.content_relay_replica_report.page_1')->getPath());
  }

  /**
   * What arrives is passed on, never back to where it came from.
   */
  public function testReceivedContentIsPassedOn(): void {
    // Made and removed here to build the snapshot, which this site, as an
    // origin, records too; only what follows counts.
    $snapshot = $this->originArticle('From upstream');
    $after = $this->lastRow();
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->assertSame(['create' => [self::DOWNSTREAM]], $this->recorded($snapshot['uuid'], $after));

    // Edited here, it goes to both, upstream included.
    $after = $this->lastRow();
    $this->local($snapshot['uuid'])->setTitle('Edited on the hub')->save();
    $this->assertSame(['update' => [self::DOWNSTREAM, self::ORIGIN]], $this->recorded($snapshot['uuid'], $after));

    // Deleted upstream: passed on, not sent back.
    $after = $this->lastRow();
    $this->assertSame(ApplyResult::DELETED, $this->apply($this->event(NULL, Operation::Delete, 2, ['entity_uuid' => $snapshot['uuid']]))->result);
    $this->assertSame(['delete' => [self::DOWNSTREAM]], $this->recorded($snapshot['uuid'], $after));
  }

}
