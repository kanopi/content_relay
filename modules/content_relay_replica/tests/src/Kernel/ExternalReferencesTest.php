<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests references to content this replica does not subscribe to.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ExternalReferencesTest extends ReplicaTestBase {

  /**
   * An article owned by a user that exists only on the origin.
   */
  private function articleByOriginUser(): array {
    $author = User::create(['name' => 'origin-author', 'status' => 1]);
    $author->save();
    $snapshot = $this->originArticle('Authored', ['uid' => $author->id()]);
    $uuid = $author->uuid();
    $author->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    return [$snapshot, $uuid];
  }

  /**
   * An unknown author outside the subscription falls back to anonymous.
   */
  public function testUserFallsBackToAnonymous(): void {
    [$snapshot, $author] = $this->articleByOriginUser();
    $event = $this->event($snapshot, Operation::Create, 1, [
      'external_references' => [['entity_type' => 'user', 'uuid' => $author]],
    ]);
    $result = $this->apply($event);
    $this->assertSame([200, ApplyResult::APPLIED, NULL], [$result->httpStatus, $result->result, $result->code]);
    $this->assertSame(0, (int) $this->local($snapshot['uuid'])->getOwnerId());
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status'], 'The substitution is part of what the copy is checked against.');
    $this->assertSame($event->hashes->entity, $state['source_hash'], 'The origin\'s hash is still what is recorded as received.');
  }

  /**
   * A configured local user is used instead.
   */
  public function testConfiguredFallback(): void {
    $editor = User::create(['name' => 'local-editor', 'status' => 1]);
    $editor->save();
    $this->config('content_relay_replica.settings')->set('external_references.fallbacks.user', (string) $editor->id())->save();
    [$snapshot, $author] = $this->articleByOriginUser();
    $result = $this->apply($this->event($snapshot, Operation::Create, 1, [
      'external_references' => [['entity_type' => 'user', 'uuid' => $author]],
    ]));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame((int) $editor->id(), (int) $this->local($snapshot['uuid'])->getOwnerId());
    $this->assertSame(SyncStatus::SYNCED, $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['status']);
  }

  /**
   * An author the origin will send is waited for instead.
   */
  public function testSubscribedUserIsWaitedFor(): void {
    [$snapshot] = $this->articleByOriginUser();
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame('waiting_dependency', $result->code);
  }

  /**
   * An author that does exist here is used, external or not.
   */
  public function testExistingUserIsUsed(): void {
    $author = User::create(['name' => 'shared', 'status' => 1]);
    $author->save();
    $snapshot = $this->originArticle('By a shared user', ['uid' => $author->id()]);
    $this->apply($this->event($snapshot, Operation::Create, 1, [
      'external_references' => [['entity_type' => 'user', 'uuid' => $author->uuid()]],
    ]));
    $this->assertSame((int) $author->id(), (int) $this->local($snapshot['uuid'])->getOwnerId());
  }

}
