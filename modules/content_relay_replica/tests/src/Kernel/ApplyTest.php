<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Hook\ApplyFailedEvent;
use Drupal\content_relay\Event\Hook\ContentRelayEvents;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests applying events: CRUD, ordering, collisions and save failures.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ApplyTest extends ReplicaTestBase {

  /**
   * Unpublishing and republishing follow the origin, without moderation.
   */
  public function testUnpublishAndRepublish(): void {
    $snapshot = $this->originArticle('Published');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertTrue($this->local($snapshot['uuid'])->isPublished());

    $unpublished = $snapshot;
    $unpublished['shared']['status'][0]['value'] = FALSE;
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($unpublished, Operation::Update, 2))->result);
    $this->assertFalse($this->local($snapshot['uuid'])->isPublished());
    $this->assertSame(SyncStatus::SYNCED, $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['status']);

    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Update, 3))->result);
    $node = $this->local($snapshot['uuid']);
    $this->assertTrue($node->isPublished());
    $this->assertCount(3, $this->revisions($node));
  }

  /**
   * Create, update and delete, with the same UUID and matching hashes.
   */
  public function testCreateUpdateDelete(): void {
    $snapshot = $this->originArticle('Hello 世界');
    $create = $this->event($snapshot, Operation::Create, 10);
    $result = $this->apply($create);
    $this->assertSame([200, ApplyResult::APPLIED, NULL], [$result->httpStatus, $result->result, $result->code]);

    $node = $this->local($snapshot['uuid']);
    $this->assertSame('Hello 世界', $node->label());
    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status']);
    $this->assertSame($create->hashes->entity, $state['source_hash']);
    $this->assertSame($create->hashes->entity, $state['local_hash'], 'The copy hashes the same as the origin.');
    $this->assertSame('10', (string) $state['source_revision']);

    $update = $this->event($this->retitle($snapshot, 'Updated'), Operation::Update, 11);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($update)->result);
    $node = $this->local($snapshot['uuid']);
    $this->assertSame('Updated', $node->label());
    $this->assertCount(2, $this->revisions($node));
    $this->assertSame(
      sprintf('Synced from origin Origin revision 11 (event %s)', $update->eventId),
      $node->getRevisionLogMessage(),
    );
    $this->assertSame(
      (int) $snapshot['metadata']['shared']['changed'][0]['value'],
      (int) $node->getChangedTime(),
      "The origin's changed time is kept.",
    );

    $delete = $this->event(NULL, Operation::Delete, 11, ['entity_uuid' => $snapshot['uuid']]);
    $this->assertSame(ApplyResult::DELETED, $this->apply($delete)->result);
    $this->assertNull($this->local($snapshot['uuid']));
    $this->assertNull($this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']));
  }

  /**
   * The same event delivered twice is applied once and answered the same.
   */
  public function testDuplicateIsAppliedOnce(): void {
    $snapshot = $this->originArticle();
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $update = $this->event($this->retitle($snapshot, 'Twice'), Operation::Update, 2);
    $first = $this->apply($update);
    $second = $this->apply($update);
    $this->assertEquals($first, $second);
    $this->assertCount(2, $this->revisions($this->local($snapshot['uuid'])));
  }

  /**
   * An older revision arriving after a newer one is refused as stale.
   */
  public function testOutOfOrderIsStale(): void {
    $snapshot = $this->originArticle('v1');
    $old = $this->event($this->retitle($snapshot, 'v2'), Operation::Update, 2);
    $new = $this->event($this->retitle($snapshot, 'v3'), Operation::Create, 3);
    $this->apply($new);
    $result = $this->apply($old);
    $this->assertSame([409, ApplyResult::STALE], [$result->httpStatus, $result->result]);
    $this->assertSame('v3', $this->local($snapshot['uuid'])->label());

    // A resend of the revision already here changes nothing.
    $same = $this->event($this->retitle($snapshot, 'v3'), Operation::Update, 3);
    $this->assertSame(ApplyResult::UNCHANGED, $this->apply($same)->result);
  }

  /**
   * Content created on the replica is never overwritten through its UUID.
   */
  public function testUuidCollisionIsRefused(): void {
    $snapshot = $this->originArticle('From origin');
    Node::create(['type' => 'article', 'title' => 'Local', 'uid' => 0, 'uuid' => $snapshot['uuid']])->save();
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame(
      [422, ApplyResult::APPLY_FAILED, 'uuid_collision'],
      [$result->httpStatus, $result->result, $result->code],
    );
    $this->assertSame('Local', $this->local($snapshot['uuid'])->label());

    // Deletes are refused the same way.
    $delete = $this->apply($this->event(NULL, Operation::Delete, 2, ['entity_uuid' => $snapshot['uuid']]));
    $this->assertSame('uuid_collision', $delete->code);
    $this->assertNotNull($this->local($snapshot['uuid']));
  }

  /**
   * A snapshot changed after hashing is refused before anything is written.
   */
  public function testHashMismatchIsRefused(): void {
    $snapshot = $this->originArticle('Original');
    $event = $this->event($snapshot, Operation::Create, 1)->toArray();
    $event['snapshot']['shared']['title'][0]['value'] = 'Tampered';
    $result = $this->apply(SyncEvent::fromArray($event));
    $this->assertSame([400, 'hash_mismatch'], [$result->httpStatus, $result->code]);
    $this->assertNull($this->local($snapshot['uuid']));
  }

  /**
   * A validation failure saves nothing and is reported field by field.
   */
  public function testValidationFailureKeepsPreviousVersion(): void {
    $snapshot = $this->originArticle('Good');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $result = $this->apply($this->event($this->retitle($snapshot, str_repeat('x', 300)), Operation::Update, 2));

    $this->assertSame(
      [422, ApplyResult::APPLY_FAILED, 'validation_failed'],
      [$result->httpStatus, $result->result, $result->code],
    );
    $this->assertSame('title', $result->details['violations'][0]['field']);
    $this->assertSame('en', $result->details['violations'][0]['langcode']);
    $this->assertSame('Good', $this->local($snapshot['uuid'])->label());

    $state = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame([SyncStatus::APPLY_FAILED, '1'], [$state['status'], (string) $state['source_revision']]);
    $failure = $this->container->get('content_relay_replica.failed_events')->getOpenForEntity('node', $snapshot['uuid'])[0];
    $this->assertSame(FailedEventStore::PERMANENT, $failure['failure_class']);
    $this->assertSame('title', json_decode($failure['violations'], TRUE)[0]['field']);
  }

  /**
   * An exception in a hook rolls back the entity and its sync state together.
   */
  public function testExceptionRollsBackEverything(): void {
    $snapshot = $this->originArticle('Before');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $before = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);

    $result = $this->apply($this->event($this->retitle($snapshot, 'Explode'), Operation::Update, 2));
    $this->assertSame([422, 'save_failed'], [$result->httpStatus, $result->code]);

    $node = $this->local($snapshot['uuid']);
    $this->assertSame('Before', $node->label());
    $this->assertCount(1, $this->revisions($node));
    $after = $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']);
    $this->assertSame(
      [$before['source_revision'], $before['source_hash']],
      [$after['source_revision'], $after['source_hash']],
    );

    // What the origin is told is safe: no paths, no secrets, no message.
    $body = json_encode($result->toArray('x'));
    $this->assertStringNotContainsString('/var/www', $body);
    $this->assertStringNotContainsString('hunter2', $body);
    $this->assertStringContainsString('RuntimeException', $body);
    // The replica keeps the full message for its administrators.
    $failure = $this->container->get('content_relay_replica.failed_events')->getOpenForEntity('node', $snapshot['uuid'])[0];
    $this->assertStringContainsString('Exploded', $failure['message']);
  }

  /**
   * A failed create is listed by UUID and label although no entity exists.
   */
  public function testFailedCreateIsListed(): void {
    $snapshot = $this->retitle($this->originArticle(), 'Explode');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertNull($this->local($snapshot['uuid']));
    $this->assertNull($this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid']));
    $failure = $this->container->get('content_relay_replica.failed_events')->getOpenForEntity('node', $snapshot['uuid'])[0];
    $this->assertSame(['Explode', 'create'], [$failure['label'], $failure['operation']]);
    $this->assertSame(1, $this->container->get('content_relay_replica.failed_events')->countOpen());
  }

  /**
   * A deadlock is retried locally and succeeds without the origin.
   */
  public function testTransientFailureIsRetriedLocally(): void {
    $snapshot = $this->originArticle('Retried');
    \Drupal::state()->set('content_relay_test.deadlocks', 1);
    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame([202, ApplyResult::ACCEPTED, 'retrying'], [$result->httpStatus, $result->result, $result->code]);
    $failures = $this->container->get('content_relay_replica.failed_events');
    $failure = $failures->getOpenForEntity('node', $snapshot['uuid'])[0];
    $this->assertSame(FailedEventStore::TRANSIENT, $failure['failure_class']);
    $this->assertGreaterThan(time(), (int) $failure['next_retry']);

    // When due, cron queues it and the worker applies the stored copy.
    $this->container->get('database')->update(FailedEventStore::TABLE)->fields(['next_retry' => 0])->execute();
    content_relay_replica_cron();
    $this->runRetryQueue();
    $this->assertSame('Retried', $this->local($snapshot['uuid'])->label());
    $this->assertSame(FailedEventStore::RESOLVED, $failures->load((int) $failure['id'])['status']);
    $this->assertSame(0, $failures->countOpen());
  }

  /**
   * A transient failure that keeps happening becomes permanent.
   */
  public function testTransientFailureGivesUp(): void {
    $this->config('content_relay_replica.settings')->set('failures.transient_retry_limit', 2)->save();
    $snapshot = $this->originArticle();
    \Drupal::state()->set('content_relay_test.deadlocks', 100);
    $event = $this->event($snapshot, Operation::Create, 1);
    $applier = $this->container->get('content_relay_replica.applier');
    $this->assertSame(202, $applier->apply($event, self::ORIGIN)->httpStatus);
    $this->assertSame(202, $applier->apply($event, self::ORIGIN, TRUE)->httpStatus);
    $last = $applier->apply($event, self::ORIGIN, TRUE);
    $this->assertSame([422, ApplyResult::APPLY_FAILED], [$last->httpStatus, $last->result]);
    $this->assertStringContainsString('Gave up after 3 attempts', $last->message);
    $failure = $this->container->get('content_relay_replica.failed_events')->getByEvent($event->eventId);
    $this->assertSame([FailedEventStore::PERMANENT, NULL], [$failure['failure_class'], $failure['next_retry']]);
  }

  /**
   * An item referencing something not here yet waits, then applies by itself.
   */
  public function testDependencyWaitsThenApplies(): void {
    $term = Term::create(['vid' => 'tags', 'name' => 'Tag']);
    $term->save();
    $term_snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($term)), TRUE);
    $snapshot = $this->originArticle('Tagged', ['field_tags' => [$term]]);
    $term->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();

    $result = $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertSame([202, 'waiting_dependency'], [$result->httpStatus, $result->code]);
    $this->assertSame(
      [['entity_type' => 'taxonomy_term', 'uuid' => $term_snapshot['uuid']]],
      $result->details['dependencies'],
    );
    $this->assertNull($this->local($snapshot['uuid']));

    $term_event = $this->event($term_snapshot, Operation::Create, 1, [
      'entity_type' => 'taxonomy_term',
      'bundle' => 'tags',
    ]);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($term_event)->result);
    $this->runRetryQueue();
    $node = $this->local($snapshot['uuid']);
    $this->assertSame('Tag', $node->get('field_tags')->entity->label());
    $this->assertSame(
      SyncStatus::SYNCED,
      $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['status'],
    );
  }

  /**
   * Dismissing records who and why, and closes the failure to retries.
   */
  public function testDismiss(): void {
    $snapshot = $this->originArticle();
    $event = $this->event($this->retitle($snapshot, str_repeat('y', 300)), Operation::Create, 1);
    $this->apply($event);
    $failures = $this->container->get('content_relay_replica.failed_events');
    $failure = $failures->getByEvent($event->eventId);

    // Someone fixes the cause; here, by allowing longer titles.
    $definition = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('node')['title'];
    $this->assertSame(255, $definition->getSetting('max_length'));
    $failures->dismiss((int) $failure['id'], 1, 'Title too long; will be shortened on origin.');
    $this->assertSame(
      [FailedEventStore::DISMISSED, '1'],
      [$failures->load((int) $failure['id'])['status'], (string) $failures->load((int) $failure['id'])['dismissed_by']],
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->container->get('content_relay_replica.applier')->retry((int) $failure['id']);
  }

  /**
   * Retry of a fixable failure applies the stored event.
   */
  public function testRetryAppliesStoredEvent(): void {
    $snapshot = $this->originArticle();
    \Drupal::state()->set('content_relay_test.deadlocks', 1);
    $this->config('content_relay_replica.settings')->set('failures.transient_retry_limit', 0)->save();
    $event = $this->event($snapshot, Operation::Create, 1);
    $this->assertSame(422, $this->apply($event)->httpStatus);
    $failure = $this->container->get('content_relay_replica.failed_events')->getByEvent($event->eventId);

    $result = $this->container->get('content_relay_replica.applier')->retry((int) $failure['id']);
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertNotNull($this->local($snapshot['uuid']));
    $this->assertSame(
      200,
      $this->container->get('content_relay_replica.processed_events')->get($event->eventId)['http_status'],
      'The status the origin can query is updated.',
    );
  }

  /**
   * A newer event that succeeds supersedes an older failure.
   */
  public function testNewerSuccessSupersedesFailure(): void {
    $snapshot = $this->originArticle();
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $bad = $this->event($this->retitle($snapshot, 'Explode'), Operation::Update, 2);
    $this->apply($bad);
    $this->apply($this->event($this->retitle($snapshot, 'Fixed'), Operation::Update, 3));
    $failures = $this->container->get('content_relay_replica.failed_events');
    $this->assertSame(FailedEventStore::SUPERSEDED, $failures->getByEvent($bad->eventId)['status']);
    $this->assertSame(
      SyncStatus::SYNCED,
      $this->container->get('content_relay_replica.sync_state')->get('node', $snapshot['uuid'])['status'],
    );
    $this->assertSame(0, $failures->countOpen());
  }

  /**
   * Failures are announced for alerting, and on the status report.
   */
  public function testFailureIsAnnounced(): void {
    $received = [];
    $this->container->get('event_dispatcher')->addListener(ContentRelayEvents::APPLY_FAILED, function (ApplyFailedEvent $event) use (&$received) {
      $received[] = $event;
    });
    $snapshot = $this->retitle($this->originArticle(), 'Explode');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->assertCount(1, $received);
    $this->assertSame(
      [FailedEventStore::PERMANENT, 'save_failed', $snapshot['uuid'], 1],
      [$received[0]->failureClass, $received[0]->errorCode, $received[0]->event->entityUuid, $received[0]->attempts],
    );

    $this->container->get('module_handler')->loadInclude('content_relay_replica', 'install');
    $requirement = content_relay_replica_requirements('runtime')['content_relay_replica_failures'];
    $this->assertSame(REQUIREMENT_WARNING, $requirement['severity']);
    $this->assertStringContainsString('1 synced item failed to save', (string) $requirement['value']);
  }

  /**
   * Events are judged by the contract they name.
   */
  public function testContractVersions(): void {
    $snapshot = $this->originArticle();
    $unknown = $this->event($snapshot, Operation::Create, 1, ['contract_version' => 5]);
    $result = $this->apply($unknown);
    $this->assertSame([409, ApplyResult::CONTRACT_MISMATCH, 1], [
      $result->httpStatus,
      $result->result,
      $result->details['current_version'],
    ]);
    $this->assertNull($this->container->get('content_relay_replica.processed_events')->get($unknown->eventId), 'A mismatch is not recorded, so a resend is applied.');

    // Once the replica has that version, the very same event applies.
    $contracts = $this->container->get('content_relay_replica.contracts');
    $contracts->save($this->contract(5, ['node' => ['article']]));
    $this->assertSame(ApplyResult::APPLIED, $this->apply($unknown)->result);

    // The previous version is still accepted; older ones are not.
    $contracts->save($this->contract(6, ['node' => ['article']]));
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($this->retitle($snapshot, 'Under 5'), Operation::Update, 2, ['contract_version' => 5]))->result);
    $this->assertSame(ApplyResult::CONTRACT_MISMATCH, $this->apply($this->event($snapshot, Operation::Update, 3, ['contract_version' => 1]))->result);

    // A bundle the contract does not include is a mismatch too.
    $term = $this->event(NULL, Operation::Delete, 1, [
      'contract_version' => 6,
      'entity_type' => 'taxonomy_term',
      'bundle' => 'tags',
      'entity_uuid' => $snapshot['uuid'],
    ]);
    $this->assertSame(ApplyResult::CONTRACT_MISMATCH, $this->apply($term)->result);
  }

  /**
   * Without revisions, the version counter and changed time decide staleness.
   */
  public function testNonRevisionableStaleness(): void {
    // entity_test itself has a test normalizer that keeps "name" local.
    $this->container->get('content_relay_replica.contracts')->save($this->contract(2, ['entity_test_mul' => ['entity_test_mul']]));
    $entity = EntityTestMul::create(['name' => 'v1']);
    $entity->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($entity)), TRUE);
    $entity->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();

    $event = fn (int $version, int $changed, string $name) => $this->event(
      ['shared' => ['name' => [['value' => $name]]] + $snapshot['shared']] + $snapshot,
      Operation::Update,
      $version,
      [
        'entity_type' => 'entity_test_mul',
        'bundle' => 'entity_test_mul',
        'revisionable' => FALSE,
        'source_changed' => $changed,
        'contract_version' => 2,
      ],
    );
    $first = $this->apply($event(1, 100, 'v1'));
    $this->assertSame(ApplyResult::APPLIED, $first->result, json_encode($first->toArray('x')));
    $this->assertSame(ApplyResult::APPLIED, $this->apply($event(2, 200, 'v2'))->result);
    $this->assertSame(ApplyResult::STALE, $this->apply($event(1, 300, 'older counter'))->result);
    $this->assertSame(ApplyResult::STALE, $this->apply($event(2, 150, 'same counter, older'))->result);
    $this->container->get('entity_type.manager')->getStorage('entity_test_mul')->resetCache();
    $stored = $this->container->get('entity.repository')->loadEntityByUuid('entity_test_mul', $snapshot['uuid']);
    $this->assertSame('v2', $stored->label());
  }

}
