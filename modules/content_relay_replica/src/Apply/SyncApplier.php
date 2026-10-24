<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Apply;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Event\Hook\ApplyEvent;
use Drupal\content_relay\Event\Hook\ApplyFailedEvent;
use Drupal\content_relay\Event\Hook\ContentRelayEvents;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\ProcessedEvents;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Applies sync events to this site. The one place that writes synced content.
 *
 * Webhook processing, queued processing, retries, manual pulls and
 * reconciliation all come through here, so the rules are the same whichever
 * way an event arrives:
 *
 * 1. An event already handled gets the same answer again, unapplied.
 * 2. A snapshot that does not match its signed hash is refused.
 * 3. A lock on the item's UUID is held throughout, so two processes never
 *    write the same item at once.
 * 4. A local item with the same UUID but no sync state is never touched: it
 *    belongs to this site.
 * 5. An event older than what is already here is refused as stale. This is
 *    decided now, under the lock, not when the event was received.
 * 6. Everything, the entity with its translations and the sync state row,
 *    is written in one transaction. On any failure it rolls back and the
 *    previous version stays exactly as it was.
 * 7. After saving, the copy is hashed again and compared with the origin's
 *    hash.
 *
 * Failures are recorded on this site with the full event, so they can be
 * seen, retried and dismissed here; see FailedEventStore.
 */
class SyncApplier {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
    protected SyncStateStore $syncState,
    protected ProcessedEvents $processed,
    protected FailedEventStore $failures,
    protected FailureClassifier $classifier,
    protected OriginPeer $origin,
    protected ContractStore $contracts,
    protected Connection $database,
    protected LockBackendInterface $lock,
    protected EventDispatcherInterface $eventDispatcher,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Applies an event.
   *
   * @param \Drupal\content_relay\Event\SyncEvent $event
   *   The verified event.
   * @param string $source_site
   *   The site that sent it.
   * @param bool $is_retry
   *   TRUE to apply even though the event was handled before, for retries of
   *   recorded failures.
   */
  public function apply(SyncEvent $event, string $source_site, bool $is_retry = FALSE): ApplyResult {
    if (!$is_retry && ($record = $this->processed->get($event->eventId))) {
      return ApplyResult::fromRecord($record);
    }
    if ($event->snapshot !== NULL && ($event->hashes === NULL || !$this->hasher->matches($event->snapshot, $event->hashes))) {
      return new ApplyResult(400, ApplyResult::REJECTED, 'hash_mismatch', 'The snapshot does not match its hash.');
    }
    // Contract before anything else: an event built under terms this replica
    // does not have cannot be judged, and is not recorded as handled, so the
    // origin's resend after sending the contract is applied normally.
    $contract = $this->contracts->accepts($event->contractVersion);
    if ($contract === NULL || !$contract->allowsBundle($event->entityType, $event->bundle)) {
      $current = $this->contracts->getCurrent()?->version();
      return new ApplyResult(409, ApplyResult::CONTRACT_MISMATCH, NULL, $contract === NULL
        ? sprintf('This replica does not accept contract version %d; it has version %s.', $event->contractVersion, $current ?? 'none')
        : sprintf('Contract version %d does not include %s %s.', $event->contractVersion, $event->entityType, $event->bundle), ['current_version' => $current]);
    }
    if (!in_array($event->operation, [Operation::Create, Operation::Update, Operation::Delete], TRUE)) {
      return new ApplyResult(400, ApplyResult::REJECTED, 'unsupported_operation', sprintf('This replica does not handle "%s" events yet.', $event->operation->value));
    }
    if (!$this->entityTypeManager->hasDefinition($event->entityType)) {
      return $this->fail($event, $source_site, FailedEventStore::PERMANENT, 'unknown_entity_type', sprintf('Entity type "%s" does not exist on this site.', $event->entityType));
    }

    $lock = 'content_relay_replica:apply:' . $event->entityType . ':' . $event->entityUuid;
    if (!$this->lock->acquire($lock, 120)) {
      $this->lock->wait($lock, 30);
      if (!$this->lock->acquire($lock, 120)) {
        return new ApplyResult(503, ApplyResult::BUSY, 'locked', 'This item is being applied by another process. Try again.');
      }
    }
    try {
      return $this->applyLocked($event, $source_site);
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Retries a recorded failure from its stored event.
   *
   * @throws \InvalidArgumentException
   *   When the failure does not exist or is no longer open.
   */
  public function retry(int $failure_id): ApplyResult {
    $failure = $this->failures->load($failure_id);
    if ($failure === NULL || $failure['status'] !== FailedEventStore::OPEN) {
      throw new \InvalidArgumentException(sprintf('Failure %d is not open.', $failure_id));
    }
    return $this->apply($this->failures->getEvent($failure), $failure['source_site'], TRUE);
  }

  /**
   * Applies an event while holding the item's lock.
   */
  protected function applyLocked(SyncEvent $event, string $source_site): ApplyResult {
    $state = $this->syncState->get($event->entityType, $event->entityUuid);
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($event->entityType, $event->entityUuid);
    $entity = $entity instanceof ContentEntityInterface ? $entity : NULL;

    if ($entity !== NULL && $state === NULL) {
      return $this->fail($event, $source_site, FailedEventStore::PERMANENT, 'uuid_collision', 'An item with this UUID exists on the replica but was not created by sync, so sync leaves it alone.');
    }
    if ($state !== NULL) {
      if ($this->isStale($event, $state)) {
        return $this->answer($event, new ApplyResult(409, ApplyResult::STALE, 'stale', sprintf('The replica already has revision %d.', $state['source_revision'])));
      }
      if ($event->hashes !== NULL && (int) $state['source_revision'] === $event->sourceRevision && $state['source_hash'] === $event->hashes->entity && $state['status'] === SyncStatus::SYNCED) {
        $this->failures->resolve($event->eventId);
        return $this->answer($event, new ApplyResult(200, ApplyResult::UNCHANGED));
      }
    }

    return $event->operation === Operation::Delete
      ? $this->applyDelete($event, $source_site, $entity, $state)
      : $this->applySnapshot($event, $source_site, $entity);
  }

  /**
   * Whether an event is older than what this replica already has.
   */
  protected function isStale(SyncEvent $event, array $state): bool {
    if ($event->sourceRevision < (int) $state['source_revision']) {
      return TRUE;
    }
    // Without revisions, the version counter is the origin's own, and the
    // changed time is a second opinion.
    return !$event->revisionable
      && $event->sourceChanged !== NULL
      && $state['source_changed'] !== NULL
      && $event->sourceChanged < (int) $state['source_changed'];
  }

  /**
   * Creates or updates an item from a snapshot.
   */
  protected function applySnapshot(SyncEvent $event, string $source_site, ?ContentEntityInterface $entity): ApplyResult {
    assert($event->snapshot !== NULL && $event->hashes !== NULL);
    $transaction = $this->database->startTransaction();
    try {
      $context = new DenormalizationContext();
      $target = $this->serializer->denormalize($event->snapshot, $context, $entity);

      if ($context->missingReferences !== []) {
        $transaction->rollBack();
        $missing = array_values($context->missingReferences);
        return $this->fail($event, $source_site, FailedEventStore::DEPENDENCY, 'waiting_dependency', sprintf('Waiting for %d referenced item(s) that are not on this site yet.', count($missing)), NULL, [], $missing);
      }
      $violations = $this->validate($target);
      if ($violations !== []) {
        $transaction->rollBack();
        return $this->fail($event, $source_site, FailedEventStore::PERMANENT, 'validation_failed', sprintf('%d value(s) failed validation on the replica.', count($violations)), NULL, $violations);
      }

      $this->prepareRevision($target, $event);
      $this->eventDispatcher->dispatch(new ApplyEvent($event, $target), ContentRelayEvents::PRE_APPLY);
      $target->save();
      $this->serializer->getUuidResolver()->reset($event->entityType);
      $this->eventDispatcher->dispatch(new ApplyEvent($event, $target), ContentRelayEvents::POST_APPLY);

      // Hash what is actually stored, not what is in memory.
      $saved = $this->entityTypeManager->getStorage($event->entityType)->loadUnchanged($target->id());
      assert($saved instanceof ContentEntityInterface);
      $local = $this->hasher->hash($this->serializer->normalize($saved, new NormalizationContext($event->langcodes)), [], $event->hashes->algorithmVersion);

      $status = SyncStatus::SYNCED;
      $error = NULL;
      if ($context->unknownFields !== []) {
        $status = SyncStatus::SCHEMA_DRIFT;
        $error = sprintf('Fields from the origin that do not exist here were ignored: %s.', implode(', ', array_keys($context->unknownFields)));
      }
      elseif (!hash_equals($event->hashes->entity, $local->entity)) {
        $status = SyncStatus::ERROR;
        $error = 'After saving, the local copy does not hash the same as the origin\'s.';
      }
      $this->syncState->save($event->entityType, $event->entityUuid, [
        'bundle' => $event->bundle,
        'source_site' => $source_site,
        'source_revision' => $event->sourceRevision,
        'source_changed' => $event->sourceChanged,
        'hash_algorithm' => $event->hashes->algorithmVersion,
        'source_hash' => $event->hashes->entity,
        'source_translation_hashes' => $event->hashes->translations,
        'local_hash' => $local->entity,
        'import_mode' => 'as_is',
        'status' => $status,
        'last_error' => $error,
        'last_event_id' => $event->eventId,
      ]);
      $this->failures->resolve($event->eventId);
      $this->failures->supersede($event->entityType, $event->entityUuid, $event->eventId);
      $result = $this->answer($event, new ApplyResult(200, ApplyResult::APPLIED, $status === SyncStatus::SYNCED ? NULL : $status));
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      return $this->failFromException($event, $source_site, $e);
    }
    unset($transaction);

    // Committed: anything that was waiting for this item can go now.
    $this->failures->retryDependents($event->entityType, $event->entityUuid);
    if ($error !== NULL) {
      $this->logger->warning('@type @uuid was saved from event @event but is flagged @status: @error', [
        '@type' => $event->entityType,
        '@uuid' => $event->entityUuid,
        '@event' => $event->eventId,
        '@status' => $status,
        '@error' => $error,
      ]);
    }
    return $result;
  }

  /**
   * Deletes a synced item.
   */
  protected function applyDelete(SyncEvent $event, string $source_site, ?ContentEntityInterface $entity, ?array $state): ApplyResult {
    if ($entity === NULL) {
      if ($state !== NULL) {
        $this->syncState->delete($event->entityType, $event->entityUuid);
      }
      return $this->answer($event, new ApplyResult(200, ApplyResult::DELETED, 'already_gone'));
    }
    $transaction = $this->database->startTransaction();
    try {
      $this->eventDispatcher->dispatch(new ApplyEvent($event, $entity), ContentRelayEvents::PRE_APPLY);
      $entity->delete();
      $this->syncState->delete($event->entityType, $event->entityUuid);
      $this->serializer->getUuidResolver()->reset($event->entityType);
      $this->eventDispatcher->dispatch(new ApplyEvent($event, $entity), ContentRelayEvents::POST_APPLY);
      $this->failures->resolve($event->eventId);
      $this->failures->supersede($event->entityType, $event->entityUuid, $event->eventId);
      $result = $this->answer($event, new ApplyResult(200, ApplyResult::DELETED));
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      return $this->failFromException($event, $source_site, $e);
    }
    return $result;
  }

  /**
   * Validates every translation, ignoring checks sync handles itself.
   *
   * @return array[]
   *   Violations, each with field, langcode and message.
   */
  protected function validate(ContentEntityInterface $entity): array {
    $violations = [];
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      foreach ($entity->getTranslation($langcode)->validate() as $violation) {
        // "Modified by another user" compares changed times. Sync keeps the
        // origin's changed time, and decides staleness by revision itself.
        if ($violation->getConstraint() instanceof EntityChangedConstraint) {
          continue;
        }
        $field = explode('.', (string) $violation->getPropertyPath())[0];
        $message = strip_tags((string) $violation->getMessage());
        $violations[$field . '|' . $langcode . '|' . $message] = [
          'field' => $field,
          'langcode' => (string) $langcode,
          'message' => $message,
        ];
      }
    }
    return array_values($violations);
  }

  /**
   * Marks the save as a sync and describes the new revision.
   */
  protected function prepareRevision(ContentEntityInterface $entity, SyncEvent $event): void {
    // Syncing keeps the origin's changed time instead of stamping now.
    $entity->setSyncing(TRUE);
    if (!$entity->getEntityType()->isRevisionable()) {
      return;
    }
    if (!$entity->isNew()) {
      $entity->setNewRevision(TRUE);
    }
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionLogMessage(sprintf('Synced from origin %s revision %d (event %s)', $this->origin->getLabel(), $event->sourceRevision, $event->eventId));
      $entity->setRevisionCreationTime($this->time->getCurrentTime());
    }
  }

  /**
   * Records the answer for an event and returns it.
   */
  protected function answer(SyncEvent $event, ApplyResult $result): ApplyResult {
    $this->processed->record($event->eventId, $event->entityType, $event->entityUuid, $result->httpStatus, $result->toArray($event->eventId));
    return $result;
  }

  /**
   * Records a failure caused by an exception.
   */
  protected function failFromException(SyncEvent $event, string $source_site, \Throwable $e): ApplyResult {
    $class = $this->classifier->isTransient($e) ? FailedEventStore::TRANSIENT : FailedEventStore::PERMANENT;
    // The origin sees only the kind of exception that started it; storage
    // wraps everything in EntityStorageException, which says nothing. The
    // message can carry file paths, SQL or values, and stays on this site.
    $cause = $e;
    while ($cause->getPrevious() !== NULL) {
      $cause = $cause->getPrevious();
    }
    $short = substr(strrchr('\\' . get_class($cause), '\\'), 1);
    return $this->fail($event, $source_site, $class, $class === FailedEventStore::TRANSIENT ? 'transient_error' : 'save_failed', sprintf('Saving failed on the replica (%s).', $short), $e);
  }

  /**
   * Records a failure, tells the item's sync state, alerts and answers.
   *
   * @param \Drupal\content_relay\Event\SyncEvent $event
   *   The event.
   * @param string $source_site
   *   Who sent it.
   * @param string $class
   *   Failure class.
   * @param string $code
   *   Error code.
   * @param string $summary
   *   Summary safe to send to the origin.
   * @param \Throwable|null $e
   *   The exception, if any. Its message is kept here only.
   * @param array $violations
   *   Field violations.
   * @param array $dependencies
   *   Missing items.
   */
  protected function fail(SyncEvent $event, string $source_site, string $class, string $code, string $summary, ?\Throwable $e = NULL, array $violations = [], array $dependencies = []): ApplyResult {
    $previous = $this->failures->getByEvent($event->eventId);
    $attempts = $previous ? (int) $previous['attempts'] + 1 : 1;
    if ($class === FailedEventStore::TRANSIENT && $this->failures->isRetryLimitReached($attempts)) {
      $class = FailedEventStore::PERMANENT;
      $summary = sprintf('%s Gave up after %d attempts.', $summary, $attempts);
    }
    $detail = $e ? sprintf('%s: %s', get_class($e), $e->getMessage()) : $summary;
    $failure = $this->failures->record($event, $source_site, $class, $code, $detail, $e ? get_class($e) : NULL, $violations, $dependencies);

    if ($this->syncState->get($event->entityType, $event->entityUuid) !== NULL) {
      $this->syncState->setStatus($event->entityType, $event->entityUuid, $class === FailedEventStore::DEPENDENCY ? SyncStatus::WAITING_DEPENDENCY : SyncStatus::APPLY_FAILED, $detail);
    }
    $this->logger->error('Event @event for @type @uuid failed to apply (@class, @code): @detail', [
      '@event' => $event->eventId,
      '@type' => $event->entityType,
      '@uuid' => $event->entityUuid,
      '@class' => $class,
      '@code' => $code,
      '@detail' => $detail,
    ]);
    $this->eventDispatcher->dispatch(new ApplyFailedEvent($event, $class, $code, $detail, $violations, (int) $failure['attempts']), ContentRelayEvents::APPLY_FAILED);

    $waiting_for = array_map(fn (array $d) => ['entity_type' => $d['entity_type'], 'uuid' => $d['uuid']], $dependencies);
    $result = match ($class) {
      FailedEventStore::TRANSIENT => new ApplyResult(202, ApplyResult::ACCEPTED, 'retrying', 'The replica will retry this event.'),
      FailedEventStore::DEPENDENCY => new ApplyResult(202, ApplyResult::ACCEPTED, 'waiting_dependency', $summary, ['dependencies' => $waiting_for]),
      default => new ApplyResult(422, ApplyResult::APPLY_FAILED, $code, $summary, $violations === [] ? [] : ['violations' => $violations]),
    };
    return $this->answer($event, $result);
  }

}
