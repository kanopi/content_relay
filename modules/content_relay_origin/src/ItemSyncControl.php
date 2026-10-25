<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts and stops sending individual items, from either side.
 *
 * The origin marks an item not syncable, for every replica or for some. A
 * replica that already had it is sent a detach event (it keeps its copy) or a
 * delete, as configured. Marking it syncable again sends an attach event and
 * then the whole item.
 *
 * A replica detaches an item on its own side and tells the origin, which then
 * stops sending it creates and updates. Only the replica can reattach it.
 * The two are independent: an item detached by both is sent again only when
 * both have reversed their own detach.
 */
class ItemSyncControl {

  public function __construct(
    protected NotSyncableFlags $flags,
    protected Outbox $outbox,
    protected DeliveryScheduler $scheduler,
    protected HashStore $hashStore,
    protected ReplicaItems $items,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected SyncScope $scope,
    protected ReportIndex $report,
    protected ConfigFactoryInterface $configFactory,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
    protected TranslationBlocks $translationBlocks,
  ) {}

  /**
   * The configured behavior for replicas that already have a marked item.
   */
  public function defaultBehavior(): string {
    $behavior = $this->configFactory->get('content_relay_origin.settings')->get('not_syncable.behavior');
    return $behavior === NotSyncableFlags::REMOVE ? NotSyncableFlags::REMOVE : NotSyncableFlags::DETACH;
  }

  /**
   * Marks an item not syncable.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The item.
   * @param string[]|null $replica_ids
   *   Only for these replicas; NULL for all, now and later.
   * @param string|null $behavior
   *   NotSyncableFlags::DETACH or ::REMOVE; the configured one if NULL.
   *
   * @return string[]
   *   The replicas sent a detach or delete.
   */
  public function markNotSyncable(ContentEntityInterface $entity, ?array $replica_ids = NULL, ?string $behavior = NULL): array {
    $behavior ??= $this->defaultBehavior();
    if (!in_array($behavior, [NotSyncableFlags::DETACH, NotSyncableFlags::REMOVE], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown not syncable behavior "%s".', $behavior));
    }
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = (string) $entity->uuid();
    $before = $this->blockedReplicas($entity_type_id, $uuid);
    foreach ($replica_ids ?? [NotSyncableFlags::ALL] as $replica_id) {
      $this->flags->set($entity_type_id, $uuid, $entity->bundle(), $replica_id, $behavior, (int) $this->currentUser->id());
    }
    // Only replicas that had the item and were not already cut off from it
    // need telling.
    $tell = array_values(array_filter(
      array_diff($this->blockedReplicas($entity_type_id, $uuid), $before),
      fn (string $id) => $this->items->isKnown($id, $entity_type_id, $uuid),
    ));
    if ($tell !== []) {
      $operation = $behavior === NotSyncableFlags::REMOVE ? Operation::Delete : Operation::Detach;
      $this->outbox->enqueue($operation, $entity_type_id, $entity->bundle(), $uuid, $this->currentRevision($entity), $tell, (int) $this->currentUser->id(), reason: Outbox::REASON_NOT_SYNCABLE);
      $this->scheduler->schedule($tell);
    }
    if (!$entity->isNew()) {
      $this->report->refreshEntity($entity);
    }
    $this->logger->notice('@user marked @type @uuid not syncable for @replicas (@behavior).', [
      '@user' => $this->currentUser->getAccountName(),
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
      '@replicas' => $replica_ids === NULL ? 'all replicas' : implode(', ', $replica_ids),
      '@behavior' => $behavior,
    ]);
    return $tell;
  }

  /**
   * Marks an item syncable again.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The item.
   * @param string[]|null $replica_ids
   *   Remove only the flags for these replicas (NotSyncableFlags::ALL for the
   *   one that applies to all); NULL removes every flag.
   *
   * @return string[]
   *   The replicas sent an attach and the item.
   */
  public function markSyncable(ContentEntityInterface $entity, ?array $replica_ids = NULL): array {
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = (string) $entity->uuid();
    $before = $this->blockedReplicas($entity_type_id, $uuid);
    $this->flags->clear($entity_type_id, $uuid, $replica_ids);
    $resumed = array_values(array_diff($before, $this->blockedReplicas($entity_type_id, $uuid)));
    if ($resumed !== [] && $this->scope->applies($entity)) {
      $uid = (int) $this->currentUser->id();
      $revision = $this->currentRevision($entity);
      // The attach tells a replica that kept a detached copy to accept
      // updates again; the update that follows brings it up to date, or
      // creates it where it was removed or never sent.
      $this->outbox->enqueue(Operation::Attach, $entity_type_id, $entity->bundle(), $uuid, $revision, $resumed, $uid, reason: Outbox::REASON_ATTACH);
      $this->outbox->enqueue(Operation::Update, $entity_type_id, $entity->bundle(), $uuid, $revision, $resumed, $uid, reason: Outbox::REASON_ATTACH);
      $this->scheduler->schedule($resumed);
    }
    if (!$entity->isNew()) {
      $this->report->refreshEntity($entity);
    }
    $this->logger->notice('@user marked @type @uuid syncable for @replicas.', [
      '@user' => $this->currentUser->getAccountName(),
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
      '@replicas' => $replica_ids === NULL ? 'all replicas' : implode(', ', $replica_ids),
    ]);
    return $resumed;
  }

  /**
   * Records that a replica detached an item.
   *
   * @param string $replica_id
   *   The replica.
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string $bundle
   *   Bundle.
   * @param string|null $by
   *   Who, as the replica reported.
   * @param int|null $at
   *   When, as the replica reported.
   */
  public function replicaDetached(string $replica_id, string $entity_type_id, string $uuid, string $bundle, ?string $by = NULL, ?int $at = NULL): void {
    if ($this->items->markDetached($replica_id, $entity_type_id, $uuid, $bundle, $by, $at)) {
      $this->logger->notice('Replica @replica detached @type @uuid (by @by).', [
        '@replica' => $replica_id,
        '@type' => $entity_type_id,
        '@uuid' => $uuid,
        '@by' => $by ?? 'unknown',
      ]);
    }
    $this->report->refresh($entity_type_id, $uuid);
  }

  /**
   * Marks one translation not syncable, or syncable again.
   *
   * Replicas keep what they have of the translation: they are sent the item
   * without it, naming it as withheld. Marking it syncable sends it again.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The item.
   * @param string $langcode
   *   The translation.
   * @param bool $syncable
   *   FALSE to stop sending it, TRUE to send it again.
   * @param string[]|null $replica_ids
   *   Only for these replicas; NULL for all (NotSyncableFlags::ALL).
   *
   * @return string[]
   *   The replicas sent the item again.
   */
  public function setTranslationSyncable(ContentEntityInterface $entity, string $langcode, bool $syncable, ?array $replica_ids = NULL): array {
    if (!$entity->hasTranslation($langcode)) {
      throw new \InvalidArgumentException(sprintf('The item has no "%s" translation.', $langcode));
    }
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = (string) $entity->uuid();
    foreach ($replica_ids ?? [NotSyncableFlags::ALL] as $replica_id) {
      $syncable
        ? $this->translationBlocks->clear($entity_type_id, $uuid, $langcode, $replica_id, TranslationBlocks::ORIGIN)
        : $this->translationBlocks->set($entity_type_id, $uuid, $langcode, $replica_id, TranslationBlocks::ORIGIN, $this->currentUser->getAccountName());
    }
    $this->logger->notice('@user marked the @langcode translation of @type @uuid @state for @replicas.', [
      '@user' => $this->currentUser->getAccountName(),
      '@langcode' => $langcode,
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
      '@state' => $syncable ? 'syncable' : 'not syncable',
      '@replicas' => $replica_ids === NULL ? 'all replicas' : implode(', ', $replica_ids),
    ]);
    return $this->resend($entity_type_id, $uuid, $entity->bundle(), $replica_ids);
  }

  /**
   * Records that a replica detached or reattached one translation.
   *
   * @return bool
   *   TRUE if the item was queued for the replica again, after a reattach.
   */
  public function replicaTranslationDetached(string $replica_id, string $entity_type_id, string $uuid, string $bundle, string $langcode, bool $detached, ?string $by = NULL): bool {
    $detached
      ? $this->translationBlocks->set($entity_type_id, $uuid, $langcode, $replica_id, TranslationBlocks::REPLICA, $by)
      : $this->translationBlocks->clear($entity_type_id, $uuid, $langcode, $replica_id, TranslationBlocks::REPLICA);
    $this->logger->notice('Replica @replica @action the @langcode translation of @type @uuid (by @by).', [
      '@replica' => $replica_id,
      '@action' => $detached ? 'detached' : 'reattached',
      '@langcode' => $langcode,
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
      '@by' => $by ?? 'unknown',
    ]);
    $queued = $detached ? [] : $this->resend($entity_type_id, $uuid, $bundle, [$replica_id]);
    $this->report->refresh($entity_type_id, $uuid);
    return $queued !== [];
  }

  /**
   * Queues an item's current version for replicas that have it.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string $bundle
   *   Bundle.
   * @param string[]|null $replica_ids
   *   Replicas, or NULL (or NotSyncableFlags::ALL among them) for every one.
   *
   * @return string[]
   *   The replicas it was queued for.
   */
  protected function resend(string $entity_type_id, string $uuid, string $bundle, ?array $replica_ids): array {
    $latest = $this->hashStore->latest($entity_type_id, $uuid);
    if ($latest === NULL) {
      return [];
    }
    $targets = [];
    foreach ($this->registry->getAll() as $replica) {
      $wanted = $replica_ids === NULL || in_array(NotSyncableFlags::ALL, $replica_ids, TRUE) || in_array($replica->id, $replica_ids, TRUE);
      if ($wanted && $replica->receivesEvents() && $this->items->isKnown($replica->id, $entity_type_id, $uuid) && $this->blockReason($replica->id, $entity_type_id, $uuid, $bundle) === NULL) {
        $targets[] = $replica->id;
      }
    }
    if ($targets !== []) {
      $this->outbox->enqueue(Operation::Update, $entity_type_id, $bundle, $uuid, $latest['revision'], $targets, (int) $this->currentUser->id(), reason: Outbox::REASON_ATTACH);
      $this->scheduler->schedule($targets);
    }
    $this->report->refresh($entity_type_id, $uuid);
    return $targets;
  }

  /**
   * Records that a replica reattached an item, and sends it the item.
   *
   * @return bool
   *   TRUE if an update was queued.
   */
  public function replicaAttached(string $replica_id, string $entity_type_id, string $uuid, ?string $by = NULL): bool {
    $this->items->markAttached($replica_id, $entity_type_id, $uuid);
    $this->logger->notice('Replica @replica reattached @type @uuid (by @by).', [
      '@replica' => $replica_id,
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
      '@by' => $by ?? 'unknown',
    ]);
    $queued = FALSE;
    $latest = $this->hashStore->latest($entity_type_id, $uuid);
    $item = $this->items->get($replica_id, $entity_type_id, $uuid);
    if ($latest !== NULL && $item !== NULL && !$this->flags->isBlocked($entity_type_id, $uuid, $replica_id)) {
      $this->outbox->enqueue(Operation::Update, $entity_type_id, $item['bundle'], $uuid, $latest['revision'], [$replica_id], reason: Outbox::REASON_ATTACH);
      $this->scheduler->schedule([$replica_id]);
      $queued = TRUE;
    }
    $this->report->refresh($entity_type_id, $uuid);
    return $queued;
  }

  /**
   * Why an item is not sent to a replica, or NULL if it is.
   *
   * @return string|null
   *   not_syncable, detached_by_replica or not_in_contract.
   */
  public function blockReason(string $replica_id, string $entity_type_id, string $uuid, string $bundle): ?string {
    if ($this->flags->isBlocked($entity_type_id, $uuid, $replica_id)) {
      return ReportIndex::NOT_SYNCABLE;
    }
    if ($this->items->isDetached($replica_id, $entity_type_id, $uuid)) {
      return ReportIndex::DETACHED_BY_REPLICA;
    }
    if (!$this->contracts->coversBundle($replica_id, $entity_type_id, $bundle)) {
      return 'not_in_contract';
    }
    return NULL;
  }

  /**
   * Replicas an item is kept from by a flag.
   *
   * @return string[]
   *   Replica IDs.
   */
  protected function blockedReplicas(string $entity_type_id, string $uuid): array {
    $blocked = [];
    foreach ($this->registry->getAll() as $replica) {
      if ($replica->receivesEvents() && $this->flags->isBlocked($entity_type_id, $uuid, $replica->id)) {
        $blocked[] = $replica->id;
      }
    }
    return $blocked;
  }

  /**
   * The item's current revision, or version for types without revisions.
   */
  protected function currentRevision(ContentEntityInterface $entity): ?int {
    if ($entity->getEntityType()->isRevisionable()) {
      return (int) $entity->getRevisionId();
    }
    return $this->hashStore->latest($entity->getEntityTypeId(), (string) $entity->uuid())['revision'] ?? NULL;
  }

}
