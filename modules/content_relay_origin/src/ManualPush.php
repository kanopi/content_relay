<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\ProcessingMode;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;

/**
 * Pushes one item to replicas now, from its push form or Drush.
 *
 * A push is an ordinary update in the event log, recorded with the user who
 * triggered it, and sent at once: delivery to each target replica runs in
 * the request until the push has had its answer. Events queued before it for
 * the same replica go first, because a replica receives its stream in order.
 * A push is never folded into an earlier event for the same item: it goes
 * out itself, with the processing mode asked for.
 *
 * Without force, a replica that already acknowledged the current hash is
 * reported in sync and sent nothing. With force the current revision is sent
 * regardless; the replica answers "unchanged" without a new revision when it
 * already has it.
 */
class ManualPush {

  /**
   * Outcomes besides the event statuses.
   */
  public const IN_SYNC = 'in_sync';
  public const QUEUED = 'queued';

  public function __construct(
    protected ItemSyncControl $control,
    protected Outbox $outbox,
    protected ReplicaItems $items,
    protected HashStore $hashStore,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected DeliveryService $delivery,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Labels for push outcomes and the reasons an item is not pushed.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels by outcome.
   */
  public static function outcomeLabels(): array {
    return [
      EventStatus::APPLIED => new TranslatableMarkup('Applied'),
      EventStatus::ACCEPTED => new TranslatableMarkup('Accepted (queued on the replica)'),
      EventStatus::STALE => new TranslatableMarkup('Refused as stale or conflicting'),
      EventStatus::FAILED => new TranslatableMarkup('Failed; will be retried'),
      EventStatus::FAILED_ON_REPLICA => new TranslatableMarkup('Failed on the replica'),
      EventStatus::DEAD_LETTER => new TranslatableMarkup('Failed; dead-lettered'),
      EventStatus::SKIPPED => new TranslatableMarkup('Skipped'),
      self::IN_SYNC => new TranslatableMarkup('Already in sync; nothing sent'),
      self::QUEUED => new TranslatableMarkup('Queued; not sent yet'),
      'not_syncable' => new TranslatableMarkup('Not syncable'),
      'detached_by_replica' => new TranslatableMarkup('Detached by the replica'),
      'not_in_contract' => new TranslatableMarkup("Not in the replica's contract"),
    ];
  }

  /**
   * The replicas an item can be pushed to, whether or not it is blocked.
   *
   * @return \Drupal\content_relay_origin\Replica[]
   *   Replicas by ID.
   */
  public function targets(ContentEntityInterface $entity): array {
    $targets = [];
    foreach ($this->registry->getAll() as $replica) {
      if ($replica->receivesEvents() && $this->contracts->coversBundle($replica->id, $entity->getEntityTypeId(), $entity->bundle())) {
        $targets[$replica->id] = $replica;
      }
    }
    return $targets;
  }

  /**
   * Why an item cannot be pushed to a replica, or NULL if it can.
   */
  public function blockReason(ContentEntityInterface $entity, string $replica_id): ?string {
    return $this->control->blockReason($replica_id, $entity->getEntityTypeId(), (string) $entity->uuid(), $entity->bundle());
  }

  /**
   * Pushes an item.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The item.
   * @param string[]|null $replica_ids
   *   Target replicas; NULL for every one that receives the bundle.
   * @param bool $force
   *   Send even if the replica already has the current hash.
   * @param \Drupal\content_relay\Event\ProcessingMode|null $mode
   *   How the replica should process it; its own setting if NULL.
   *
   * @return array<string, array{status: string, row: int|null, message: string|null}>
   *   Outcome by replica ID. Status is an EventStatus value, IN_SYNC, QUEUED
   *   (not sent yet, behind a retry or outside a delivery window), or the
   *   reason it was not pushed: not_syncable, detached_by_replica or
   *   not_in_contract.
   *
   * @phpstan-impure
   */
  public function push(ContentEntityInterface $entity, ?array $replica_ids = NULL, bool $force = FALSE, ?ProcessingMode $mode = NULL): array {
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = (string) $entity->uuid();
    $latest = $this->hashStore->latest($entity_type_id, $uuid) ?? $this->hashStore->record($entity);
    $uid = (int) $this->currentUser->id();
    $targets = $this->targets($entity);
    $results = [];

    foreach ($replica_ids ?? array_keys($targets) as $replica_id) {
      $reason = isset($targets[$replica_id]) ? $this->blockReason($entity, $replica_id) : 'not_in_contract';
      if ($reason !== NULL) {
        $results[$replica_id] = ['status' => $reason, 'row' => NULL, 'message' => NULL];
        continue;
      }
      $item = $this->items->get($replica_id, $entity_type_id, $uuid);
      if (!$force && $item !== NULL && $item['entity_hash'] !== NULL && hash_equals($item['entity_hash'], $latest['hashes']->entity)
        && $this->outbox->pendingRowFor($replica_id, $entity_type_id, $uuid) === NULL) {
        $results[$replica_id] = ['status' => self::IN_SYNC, 'row' => NULL, 'message' => NULL];
        continue;
      }
      $operation = $this->items->isKnown($replica_id, $entity_type_id, $uuid) ? Operation::Update : Operation::Create;
      $ids = $this->outbox->enqueue($operation, $entity_type_id, $entity->bundle(), $uuid, $latest['revision'], [$replica_id], $uid, reason: Outbox::REASON_MANUAL, process: $mode);
      if (!isset($ids[$replica_id])) {
        $results[$replica_id] = ['status' => 'not_in_contract', 'row' => NULL, 'message' => NULL];
        continue;
      }
      $results[$replica_id] = $this->deliverNow($replica_id, $ids[$replica_id]);
      $this->logger->notice('@user pushed @type @uuid to replica @replica@force: @status.', [
        '@user' => $this->currentUser->getAccountName(),
        '@type' => $entity_type_id,
        '@uuid' => $uuid,
        '@replica' => $replica_id,
        '@force' => $force ? ' (forced)' : '',
        '@status' => $results[$replica_id]['status'],
      ]);
    }
    return $results;
  }

  /**
   * Delivers to a replica until a row has been sent or delivery pauses.
   *
   * @return array{status: string, row: int, message: string|null}
   *   The row's outcome.
   */
  protected function deliverNow(string $replica_id, int $row_id): array {
    // Each run sends up to a batch; the push may be behind other events.
    do {
      $more = $this->delivery->deliver($replica_id);
      $row = $this->outbox->load($row_id);
    } while ($more && $row !== NULL && in_array($row['status'], EventStatus::PENDING, TRUE) && (int) $row['attempts'] === 0);

    if ($row === NULL) {
      return ['status' => self::QUEUED, 'row' => $row_id, 'message' => NULL];
    }
    $status = $row['status'];
    if ($status === EventStatus::QUEUED || ($status === EventStatus::FAILED && (int) $row['attempts'] === 0)) {
      $status = self::QUEUED;
    }
    // What the replica said about it, when it said more than the outcome.
    $body = json_decode((string) $row['last_response'], TRUE);
    $said = is_array($body) ? array_filter([$body['code'] ?? NULL, $body['message'] ?? NULL], 'is_string') : [];
    return [
      'status' => $status,
      'row' => $row_id,
      'message' => in_array($status, [EventStatus::APPLIED, self::QUEUED], TRUE) || $said === [] ? NULL : implode(': ', $said),
    ];
  }

}
