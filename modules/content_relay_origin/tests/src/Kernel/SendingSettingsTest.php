<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\Entity\ContentRelayReplicaPairing;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\Replica;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests per-replica sending settings.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class SendingSettingsTest extends OriginTestBase {

  /**
   * The replica's pairing, after pairing.
   */
  private function pairing(): ContentRelayReplicaPairing {
    $pairing = $this->container->get('content_relay_origin.sending_settings')->pairing($this->replica->siteId);
    $this->assertNotNull($pairing);
    return $pairing;
  }

  /**
   * Pairing makes a pairing, which follows the site-wide settings.
   */
  public function testPairingFollowsSiteWide(): void {
    $this->pair();
    $pairing = $this->pairing();
    $this->assertSame('Fake replica', $pairing->label());
    $this->assertSame('fake_replica', $pairing->id());
    $this->assertSame([], $pairing->getSettings());

    $sending = $this->container->get('content_relay_origin.sending_settings');
    $this->config('content_relay_origin.settings')->set('delivery.max_attempts', 4)->save();
    $this->assertSame(4, $sending->get('delivery.max_attempts', $this->replica->siteId));

    // Its own setting wins; other replicas, and the site, keep the default.
    $pairing->setSetting('delivery.max_attempts', 9)->save();
    $this->assertSame(9, $sending->get('delivery.max_attempts', $this->replica->siteId));
    $this->assertSame(4, $sending->get('delivery.max_attempts', 'another-replica'));
    $this->assertSame(4, $sending->get('delivery.max_attempts'));

    // Machine names stay unique; pairing again keeps the pairing.
    $this->assertSame('fake_replica_2', $sending->ensurePairing('another-replica', 'Fake replica')->id());
    $this->assertSame('fake_replica', $sending->ensurePairing($this->replica->siteId, 'Renamed')->id());
  }

  /**
   * Each replica has its own delivery, read limit, file links and handling.
   */
  public function testSettingsPerReplica(): void {
    $this->pair();
    $id = $this->replica->siteId;
    $this->config('content_relay_origin.settings')
      ->set('delivery.backoff_base', 10)
      ->set('delivery.backoff_max', 10)
      ->set('read_limit.items', 0)
      ->set('not_syncable.behavior', NotSyncableFlags::DETACH)
      ->set('files.link_lifetime', 3600)
      ->save();
    $this->pairing()
      ->setSetting('delivery.backoff_base', 100)
      ->setSetting('delivery.backoff_max', 100)
      ->setSetting('read_limit.items', 5)
      ->setSetting('not_syncable.behavior', NotSyncableFlags::REMOVE)
      ->setSetting('files.link_lifetime', 600)
      ->save();

    $delivery = $this->container->get('content_relay_origin.delivery');
    $this->assertGreaterThanOrEqual(50, $delivery->backoff(1, $id));
    $this->assertLessThanOrEqual(10, $delivery->backoff(1));

    $requests = $this->container->get('content_relay_origin.replica_requests');
    $this->assertSame(5, $requests->remaining($id));
    $this->assertSame(PHP_INT_MAX, $requests->remaining('another-replica'));

    $control = $this->container->get('content_relay_origin.item_sync_control');
    $this->assertSame(NotSyncableFlags::REMOVE, $control->defaultBehavior($id));
    $this->assertSame(NotSyncableFlags::DETACH, $control->defaultBehavior());
  }

  /**
   * Each replica is offered its own bundles and encryption requirement.
   */
  public function testContractPerReplica(): void {
    $this->pair(NULL, ['node' => ['article', 'page']]);
    $id = $this->replica->siteId;
    $scope = $this->container->get('content_relay_origin.sync_scope');
    $this->pairing()
      ->setSetting('bundles', ['node' => ['article']])
      ->setSetting('encryption.require', TRUE)
      ->save();

    $this->assertSame(['node' => ['article']], $scope->getPublishedBundles($id));
    $this->assertSame(['node' => ['article', 'page']], $scope->getPublishedBundles());
    $this->assertTrue($scope->isBundleSynced('node', 'page'));

    $contracts = $this->container->get('content_relay_origin.contract_manager');
    $contract = $contracts->propose($id, ['bundles' => ['node' => ['article', 'page']]], NULL, 'origin');
    $this->assertSame(['node' => ['article']], $contract->terms['effective']['bundles']);
    $this->assertTrue($contract->terms['origin_terms']['require_encryption']);

    // A bundle published to one replica only is still published.
    $this->config('content_relay_origin.settings')->set('bundles', ['node' => ['article']])->save();
    $this->pairing()->setSetting('bundles', ['node' => ['page']])->save();
    $this->assertSame(['node' => ['article', 'page']], $scope->getPublishedBundles());
    $this->assertTrue($scope->applies($this->node('page')));
  }

  /**
   * Sites updated get pairings for the replicas already paired.
   */
  public function testPostUpdate(): void {
    $manager = $this->container->get('entity.definition_update_manager');
    $this->assertNull($manager->getEntityType('content_relay_replica_pairing'));
    $this->container->get('module_handler')->loadInclude('content_relay_origin', 'install');
    content_relay_origin_update_10003();
    $this->assertNotNull($manager->getEntityType('content_relay_replica_pairing'));
    $this->config('content_relay_origin.settings')->clear('languages')->save();
    content_relay_origin_update_10005();
    $this->assertSame([], $this->config('content_relay_origin.settings')->get('languages.excluded'));

    $registry = $this->container->get('content_relay_origin.replica_registry');
    $registry->save(new Replica('11111111-1111-4111-8111-111111111111', 'Asia', 'https://asia.example.com/content-relay/v1/events', Replica::ACTIVE, []));
    $registry->save(new Replica('22222222-2222-4222-8222-222222222222', 'Europe', 'https://eu.example.com/content-relay/v1/events', Replica::ACTIVE, []));
    $this->container->get('content_relay_origin.sending_settings')->ensurePairing('22222222-2222-4222-8222-222222222222', 'Europe');

    $this->container->get('module_handler')->loadInclude('content_relay_origin', 'php', 'content_relay_origin.post_update');
    $this->assertSame('Added a pairing for one replica.', content_relay_origin_post_update_replica_pairings());
    $this->assertSame(['asia', 'europe'], array_keys($this->container->get('content_relay_origin.sending_settings')->pairings()));
  }

}
