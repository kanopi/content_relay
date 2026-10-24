<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Event\Operation;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Turns entity saves and deletes into outbox rows.
 *
 * Runs inside the entity's save transaction and does only local work: one
 * hash, one row per replica, one queue item per replica. No snapshot is sent
 * and no HTTP request is made, so an unreachable replica cannot slow down or
 * fail an editor's save, and a save that rolls back takes its rows with it.
 */
class EntityChangeRecorder {

  public function __construct(
    protected SyncScope $scope,
    protected HashStore $hashStore,
    protected Outbox $outbox,
    protected DeliveryScheduler $scheduler,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
  ) {}

  /**
   * Replicas whose contract, in force or pending, covers a bundle.
   *
   * @return string[]
   *   Replica IDs.
   */
  protected function recipients(string $entity_type_id, string $bundle): array {
    $ids = [];
    foreach ($this->registry->getAll() as $replica) {
      if ($replica->receivesEvents() && $this->contracts->coversBundle($replica->id, $entity_type_id, $bundle)) {
        $ids[] = $replica->id;
      }
    }
    return $ids;
  }

  /**
   * Records an insert or update.
   */
  public function onSave(EntityInterface $entity, bool $is_new): void {
    if (!$this->scope->applies($entity)) {
      return;
    }
    assert($entity instanceof ContentEntityInterface);
    ['revision' => $revision] = $this->hashStore->record($entity);
    $rows = $this->outbox->enqueue($is_new ? Operation::Create : Operation::Update, $entity->getEntityTypeId(), $entity->bundle(), $entity->uuid(), $revision, $this->recipients($entity->getEntityTypeId(), $entity->bundle()));
    $this->scheduler->schedule(array_keys($rows));
  }

  /**
   * Records a delete.
   */
  public function onDelete(EntityInterface $entity): void {
    if (!$this->scope->applies($entity)) {
      return;
    }
    $latest = $this->hashStore->latest($entity->getEntityTypeId(), (string) $entity->uuid());
    $revision = $entity instanceof ContentEntityInterface && $entity->getEntityType()->isRevisionable() ? (int) $entity->getRevisionId() : ($latest['revision'] ?? 0);
    $rows = $this->outbox->enqueue(Operation::Delete, $entity->getEntityTypeId(), $entity->bundle(), (string) $entity->uuid(), $revision, $this->recipients($entity->getEntityTypeId(), $entity->bundle()));
    $this->hashStore->forget($entity->getEntityTypeId(), (string) $entity->uuid());
    $this->scheduler->schedule(array_keys($rows));
  }

}
