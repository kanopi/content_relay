<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Hook\ConflictEvent;
use Drupal\content_relay\Event\Hook\ContentRelayEvents;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests local edits meeting origin updates, and local-only fields.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ConflictTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_notes', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_notes'])->save();
    $this->useFakeOrigin();
  }

  /**
   * The sync state of an item.
   */
  private function state(string $uuid): array {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid);
  }

  /**
   * Applies an article and edits it here.
   */
  private function syncedAndEdited(): array {
    $snapshot = $this->originArticle('From origin');
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $this->local($snapshot['uuid'])->setTitle('Local edit')->save();
    return $snapshot;
  }

  /**
   * By default the origin's version is saved over the edit, flagged.
   */
  public function testOverwriteKeepsTheEditInHistory(): void {
    $snapshot = $this->syncedAndEdited();
    $edit_revision = (int) $this->local($snapshot['uuid'])->getRevisionId();

    $result = $this->apply($this->event($this->retitle($snapshot, 'Origin update'), Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame('conflict_overwritten', $result->code);
    $this->assertSame('Origin update', $this->local($snapshot['uuid'])->label());
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::CONFLICT, $state['status']);
    $this->assertSame($edit_revision, (int) $state['conflict_revision']);
    $edit = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($edit_revision);
    $this->assertSame('Local edit', $edit->label());

    // Still flagged after the next update, until someone looks.
    $this->apply($this->event($this->retitle($snapshot, 'Origin again'), Operation::Update, 3));
    $this->assertSame(SyncStatus::CONFLICT, $this->state($snapshot['uuid'])['status']);
    // The same revision again changes nothing.
    $this->assertSame(ApplyResult::UNCHANGED, $this->apply($this->event($this->retitle($snapshot, 'Origin again'), Operation::Update, 3))->result);

    $diff = $this->container->get('content_relay_replica.conflicts')->diff('node', $snapshot['uuid']);
    $this->assertSame([['field' => 'Title', 'langcode' => '', 'local' => 'Local edit', 'origin' => 'Origin again']], array_values(array_filter($diff['rows'], fn ($row) => $row['field'] === 'Title')));

    // Accepting the origin's version clears the flag.
    $this->assertNull($this->container->get('content_relay_replica.conflicts')->acceptOrigin('node', $snapshot['uuid']));
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status']);
    $this->assertNull($state['conflict_revision']);
  }

  /**
   * Keeping the local version restores the edit and detaches the item.
   */
  public function testKeepLocalRestoresTheEdit(): void {
    $snapshot = $this->syncedAndEdited();
    $this->apply($this->event($this->retitle($snapshot, 'Origin update'), Operation::Update, 2));

    $this->container->get('content_relay_replica.conflicts')->keepLocal('node', $snapshot['uuid']);
    $this->assertSame('Local edit', $this->local($snapshot['uuid'])->label());
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::DETACHED_BY_REPLICA, $state['status']);
    $this->assertNull($state['conflict_revision']);
    $this->assertSame(ApplyResult::SKIPPED_DETACHED, $this->apply($this->event($this->retitle($snapshot, 'Ignored'), Operation::Update, 3))->result);
  }

  /**
   * With "reject", pushed events leave an edited item alone.
   */
  public function testRejectSetting(): void {
    $this->config('content_relay_replica.settings')->set('conflicts.on_event', 'reject')->save();
    $snapshot = $this->syncedAndEdited();
    $revisions = count($this->revisions($this->local($snapshot['uuid'])));

    $result = $this->apply($this->event($this->retitle($snapshot, 'Origin update'), Operation::Update, 2));
    $this->assertSame(409, $result->httpStatus);
    $this->assertSame(ApplyResult::CONFLICT, $result->result);
    $this->assertSame('Local edit', $this->local($snapshot['uuid'])->label());
    $this->assertCount($revisions, $this->revisions($this->local($snapshot['uuid'])));
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::CONFLICT, $state['status']);
    $this->assertSame(1, (int) $state['source_revision']);
    $this->assertNotNull($state['conflict_at']);
  }

  /**
   * Subscribers can decide.
   */
  public function testConflictEventCanReject(): void {
    $seen = [];
    $this->container->get('event_dispatcher')->addListener(ContentRelayEvents::CONFLICT, function (ConflictEvent $event) use (&$seen): void {
      $seen[] = $event->editedTranslations;
      $event->resolution = SyncApplier::CONFLICT_REJECT;
    });
    $snapshot = $this->syncedAndEdited();
    $this->assertSame(ApplyResult::CONFLICT, $this->apply($this->event($this->retitle($snapshot, 'Origin update'), Operation::Update, 2))->result);
    $this->assertSame([['en']], $seen);
  }

  /**
   * Pulls never overwrite edits unless the user accepts the origin's version.
   */
  public function testPullNeedsConfirmation(): void {
    $snapshot = $this->syncedAndEdited();
    $this->serveItem($this->retitle($snapshot, 'Latest'), 2);
    $puller = $this->container->get('content_relay_replica.puller');

    $result = $puller->pull('node', $snapshot['uuid']);
    $this->assertSame(ApplyResult::CONFLICT, $result->result);
    $this->assertSame('Local edit', $this->local($snapshot['uuid'])->label());

    $diff = $this->container->get('content_relay_replica.conflicts')->diff('node', $snapshot['uuid']);
    $this->assertSame("The origin's latest (revision 2)", $diff['origin']);
    $this->assertContains(['field' => 'Title', 'langcode' => '', 'local' => 'Local edit', 'origin' => 'Latest'], $diff['rows']);

    $this->serveItem($this->retitle($snapshot, 'Latest'), 2);
    $result = $this->container->get('content_relay_replica.conflicts')->acceptOrigin('node', $snapshot['uuid']);
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertSame('Latest', $this->local($snapshot['uuid'])->label());
    $state = $this->state($snapshot['uuid']);
    $this->assertSame(SyncStatus::SYNCED, $state['status']);
    $this->assertNull($state['conflict_at']);
  }

  /**
   * A pull of an item without edits just applies it.
   */
  public function testPullWithoutEdits(): void {
    $snapshot = $this->originArticle('From origin');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->serveItem($this->retitle($snapshot, 'Latest'), 2);
    $this->assertSame(ApplyResult::APPLIED, $this->container->get('content_relay_replica.puller')->pull('node', $snapshot['uuid'])->result);
    $this->assertSame('Latest', $this->local($snapshot['uuid'])->label());
  }

  /**
   * Local-only fields are never written and never make a conflict.
   */
  public function testLocalOnlyFields(): void {
    $this->config('content_relay_replica.settings')->set('local_only', [
      ['entity_type' => 'node', 'bundle' => 'article', 'fields' => ['field_notes']],
    ])->save();
    // The origin has the field too, and fills it in.
    $snapshot = $this->originArticle('From origin', ['field_notes' => 'Origin notes']);
    $this->assertSame(ApplyResult::APPLIED, $this->apply($this->event($snapshot, Operation::Create, 1))->result);
    $node = $this->local($snapshot['uuid']);
    $this->assertTrue($node->get('field_notes')->isEmpty());
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);

    $node->set('field_notes', 'Ours')->save();
    $result = $this->apply($this->event($this->retitle($snapshot, 'Updated'), Operation::Update, 2));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $this->assertNull($result->code);
    $node = $this->local($snapshot['uuid']);
    $this->assertSame('Updated', $node->label());
    $this->assertSame('Ours', $node->get('field_notes')->value);
    $this->assertSame(SyncStatus::SYNCED, $this->state($snapshot['uuid'])['status']);
  }

}
