<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the updates that repair a site where both roles clashed.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class UpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_relay_origin',
    'content_relay_replica',
    'field',
    'file',
    'filter',
    'key',
    'node',
    'system',
    'text',
    'user',
    'views',
  ];

  /**
   * A replica that tried to become an origin too, before the fix.
   */
  public function testRepairsBothRoles(): void {
    $this->container->get('module_handler')->loadInclude('content_relay_replica', 'install');
    $this->container->get('module_handler')->loadInclude('content_relay_origin', 'install');
    $schema = $this->container->get('database')->schema();
    $replica = content_relay_replica_schema();
    $origin = content_relay_origin_schema();

    // The replica's tables, its contracts under the old shared name, and the
    // origin's tables up to where its install stopped.
    foreach ($replica as $table => $definition) {
      $schema->createTable($table === ContractStore::TABLE ? 'content_relay_contract' : $table, $definition);
    }
    $schema->createTable(Outbox::TABLE, $origin[Outbox::TABLE]);
    $this->container->get('database')->insert('content_relay_contract')
      ->fields(['version' => 3, 'terms' => '{}', 'received' => 1700000000])
      ->execute();

    content_relay_replica_update_10001();
    content_relay_origin_update_10001();

    $this->assertSame('3', (string) $this->container->get('database')->query('SELECT version FROM {' . ContractStore::TABLE . '}')->fetchField(), 'The replica keeps its contracts.');
    $this->assertTrue($schema->fieldExists(ContractManager::TABLE, 'replica_id'), 'The origin has its own contract table.');
    foreach (array_keys($replica + $origin) as $table) {
      $this->assertTrue($schema->tableExists($table), $table);
    }
    $this->assertTrue($schema->tableExists(SyncStateStore::TABLE));

    // Run again, nothing changes.
    $this->assertSame('The replica keeps its contracts in their own table.', content_relay_replica_update_10001());
    $this->assertSame('Nothing to do.', content_relay_origin_update_10001());
  }

}
