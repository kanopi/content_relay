<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\ItemDetacher;
use Drupal\content_relay_replica\OriginClient;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\key\Entity\Key;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests detaching on the replica and detach events from the origin.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class DetachTest extends ReplicaTestBase {

  /**
   * The service under test.
   */
  private ItemDetacher $detacher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    [, $material] = KeyPair::generate(KeyPurpose::Signing);
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY=' . $material);
    Key::create([
      'id' => 'replica_signing',
      'label' => 'Replica signing',
      'key_type' => 'content_relay_signing',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_REPLICA_TEST_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();
    FakeOrigin::install($this->container->get('state'), 'UNUSED', []);
    $account = User::create(['name' => 'detacher', 'status' => 1]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $this->detacher = $this->container->get('content_relay_replica.detacher');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY');
    parent::tearDown();
  }

  /**
   * Notices the fake origin received.
   */
  private function notices(): array {
    return $this->container->get('state')->get(FakeOrigin::STATE)['notices'] ?? [];
  }

  /**
   * Sets whether the fake origin is reachable.
   */
  private function originDown(bool $down): void {
    $state = $this->container->get('state');
    $config = $state->get(FakeOrigin::STATE);
    $config['down'] = $down;
    $state->set(FakeOrigin::STATE, $config);
  }

  /**
   * The sync state of an item.
   */
  private function state(string $uuid): ?array {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid);
  }

  /**
   * Applies an article and returns its snapshot.
   */
  private function synced(string $title = 'Synced'): array {
    $snapshot = $this->originArticle($title);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    return $snapshot;
  }

  /**
   * A detached item is not updated, and the origin is told.
   */
  public function testDetachedItemIsNotUpdated(): void {
    $snapshot = $this->synced();
    $this->assertTrue($this->detacher->detach('node', $snapshot['uuid']));
    $this->assertFalse($this->detacher->detach('node', $snapshot['uuid']), 'Already detached.');

    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $state['status']);
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $state['detached_by']);
    $notice = $this->notices()[0];
    $this->assertSame('detach_notice', $notice['type']);
    $this->assertSame(['entity_type' => 'node', 'uuid' => $snapshot['uuid'], 'bundle' => 'article'], array_intersect_key($notice['body'], array_flip([
      'entity_type',
      'uuid',
      'bundle',
    ])));
    $this->assertSame('detacher', $notice['body']['by']);

    $result = $this->apply($this->event($this->retitle($snapshot, 'From origin'), Operation::Update, 2));
    $this->assertSame(200, $result->httpStatus);
    $this->assertSame(ApplyResult::SKIPPED_DETACHED, $result->result);
    $this->assertSame('detacher', $result->details['detached_by']);
    $this->assertSame('Synced', $this->local($snapshot['uuid'])->label());

    // A create for the same UUID cannot take the detached item over either.
    $result = $this->apply($this->event($this->retitle($snapshot, 'Takeover'), Operation::Create, 3));
    $this->assertSame(ApplyResult::SKIPPED_DETACHED, $result->result);
    $this->assertSame('Synced', $this->local($snapshot['uuid'])->label());
  }

  /**
   * Deletes of detached items are ignored unless configured to follow.
   */
  public function testDeletesOfDetachedItems(): void {
    $snapshot = $this->synced();
    $this->detacher->detach('node', $snapshot['uuid']);
    $result = $this->apply($this->event(NULL, Operation::Delete, 2, ['entity_uuid' => $snapshot['uuid']]));
    $this->assertSame(ApplyResult::SKIPPED_DETACHED, $result->result);
    $this->assertNotNull($this->local($snapshot['uuid']));

    $this->config('content_relay_replica.settings')->set('detach.follow_deletes', TRUE)->save();
    $result = $this->apply($this->event(NULL, Operation::Delete, 3, ['entity_uuid' => $snapshot['uuid']]));
    $this->assertSame(ApplyResult::DELETED, $result->result);
    $this->assertNull($this->local($snapshot['uuid']));
    $this->assertNull($this->state($snapshot['uuid']));
  }

  /**
   * Reattaching without local edits resumes syncing.
   */
  public function testReattachWithoutEdits(): void {
    $snapshot = $this->synced();
    $this->detacher->detach('node', $snapshot['uuid']);
    $this->assertNull($this->detacher->localEdits('node', $snapshot['uuid']));
    $this->assertTrue($this->detacher->reattach('node', $snapshot['uuid'], FALSE));
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);
    $this->assertSame('attach_notice', $this->notices()[1]['type']);

    $result = $this->apply($this->event($this->retitle($snapshot, 'Newer'), Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame('Newer', $this->local($snapshot['uuid'])->label());
  }

  /**
   * Local edits make reattaching a choice.
   */
  public function testReattachWithLocalEdits(): void {
    $snapshot = $this->synced();
    $this->detacher->detach('node', $snapshot['uuid']);
    $this->local($snapshot['uuid'])->setTitle('Local edit')->save();
    $this->assertSame(['en'], $this->detacher->localEdits('node', $snapshot['uuid']));

    // Keep local: stays detached, the origin is not told anything new.
    $this->assertFalse($this->detacher->reattach('node', $snapshot['uuid'], FALSE));
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->state($snapshot['uuid'])['status']);
    $this->assertCount(1, $this->notices());

    // Accept the origin's version: the same revision as before is applied
    // again, over the edit.
    $this->assertTrue($this->detacher->reattach('node', $snapshot['uuid'], TRUE));
    $this->assertSame(SyncStatus::ERROR, $this->state($snapshot['uuid'])['status']);
    $result = $this->apply($this->event($snapshot, Operation::Update, 1));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame('Synced', $this->local($snapshot['uuid'])->label());
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);
  }

  /**
   * A notice that cannot reach the origin is sent again later.
   */
  public function testFailedNoticeIsRetried(): void {
    $snapshot = $this->synced();
    $this->originDown(TRUE);
    $this->detacher->detach('node', $snapshot['uuid']);
    $client = $this->container->get('content_relay_replica.origin_client');
    $this->assertCount(1, $client->getPending());
    $this->assertSame(1, $client->sendPending());

    // Reattached while still unreachable: only the reattach is kept.
    $this->detacher->reattach('node', $snapshot['uuid']);
    $this->assertSame(['attach_notice'], array_column($client->getPending(), 'type'));

    $this->originDown(FALSE);
    content_relay_replica_cron();
    $this->assertSame([], $this->container->get('state')->get(OriginClient::STATE_PENDING));
    $this->assertSame(['attach_notice'], array_column($this->notices(), 'type'));
  }

  /**
   * Detach and attach events from the origin keep the copy.
   */
  public function testOriginDetachAndAttach(): void {
    $snapshot = $this->synced();
    $result = $this->apply($this->event(NULL, Operation::Detach, 1, ['entity_uuid' => $snapshot['uuid']]));
    $this->assertSame(ApplyResult::DETACHED, $result->result);
    $this->assertSame(SyncStatus::DETACHED_BY_ORIGIN, $this->state($snapshot['uuid'])['status']);
    $this->assertNotNull($this->local($snapshot['uuid']));

    // Edited while the origin was not sending it.
    $this->local($snapshot['uuid'])->setTitle('Local edit')->save();
    $result = $this->apply($this->event(NULL, Operation::Attach, 1, ['entity_uuid' => $snapshot['uuid']]));
    $this->assertSame(ApplyResult::ATTACHED, $result->result);
    $this->assertSame(SyncStatus::ERROR, $this->state($snapshot['uuid'])['status']);
    // The item that follows replaces the edit, though the origin did not
    // change it.
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Update, 1))->result);
    $this->assertSame('Synced', $this->local($snapshot['uuid'])->label());

    // For an item this site never received, nothing to do.
    $result = $this->apply($this->event(NULL, Operation::Detach, 1, ['entity_uuid' => '9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01']));
    $this->assertSame(ApplyResult::UNCHANGED, $result->result);
  }

  /**
   * Each side reverses only its own detach.
   */
  public function testDetachedByBothSides(): void {
    $snapshot = $this->synced();
    $uuid = $snapshot['uuid'];
    $this->apply($this->event(NULL, Operation::Detach, 1, ['entity_uuid' => $uuid]));
    $this->detacher->detach('node', $uuid);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->state($uuid)['status']);

    // The origin reattaching leaves this site's detach in place.
    $result = $this->apply($this->event(NULL, Operation::Attach, 1, ['entity_uuid' => $uuid]));
    $this->assertSame('detached_by_replica', $result->code);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $this->state($uuid)['status']);
    $this->assertSame(ApplyResult::SKIPPED_DETACHED, $this->apply($this->event($this->retitle($snapshot, 'No'), Operation::Update, 2))->result);

    // Detached by the origin again; this site reattaching leaves that.
    $this->apply($this->event(NULL, Operation::Detach, 2, ['entity_uuid' => $uuid]));
    $this->detacher->reattach('node', $uuid);
    $this->assertSame(SyncStatus::DETACHED_BY_ORIGIN, $this->state($uuid)['status']);
    $this->apply($this->event(NULL, Operation::Attach, 2, ['entity_uuid' => $uuid]));
    $this->assertSame(SyncStatus::SYNCED, $this->state($uuid)['status']);
  }

}
