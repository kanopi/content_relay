<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends a replica's due events, in order, and records each outcome.
 *
 * How a response is treated:
 *
 * - 200: applied.
 * - 202: accepted; the replica will apply it later.
 * - 409: the replica has this or a newer revision, or refused it as a
 *   conflict. Done, never retried.
 * - 200 with result "skipped_detached": the replica detached the item and
 *   did not apply it. Recorded as skipped; creates and updates of the item
 *   are no longer sent to that replica until it reattaches.
 * - 422 with result "apply_failed": the replica could not save a valid event.
 *   The replica keeps it and owns the retries; the origin records the failure
 *   and does not resend.
 * - 408, 429 and 5xx, timeouts and connection failures: retried with
 *   exponential backoff and jitter, then dead-lettered.
 * - Any other 4xx: dead-lettered at once. Resending the same thing would get
 *   the same answer.
 *
 * - 409 with result "contract_mismatch": the replica does not have the
 *   contract the event names. The contract is sent again and the event
 *   retried, rebuilt under whatever is in force.
 *
 * Before an item, everything it references that the replica subscribes to
 * and does not have yet is sent, depth first, so references resolve when the
 * item arrives. Each item is sent at most once per run, which is what stops a
 * pair of items referencing each other from looping: the second one goes
 * first and waits on the replica until the first arrives.
 *
 * Only one worker delivers to a replica at a time, and a retrying event holds
 * back everything behind it, so a replica always receives its stream in
 * order.
 *
 * Creates and updates of an item marked not syncable for the replica, or
 * detached by it, are skipped when their turn comes. Detach, attach and
 * delete events still go.
 *
 * The contract in force decides the rest: nothing is sent before the replica
 * acknowledged its first contract, while it has paused delivery, or outside
 * its delivery windows; at most its rate limit is sent per minute; and events
 * for bundles it does not receive are skipped.
 */
class DeliveryService {

  /**
   * How much of a response body to keep in the event log.
   */
  public const RESPONSE_LIMIT = 2000;

  public function __construct(
    protected ReplicaRegistry $registry,
    protected Outbox $outbox,
    protected EventBuilder $eventBuilder,
    protected ContractManager $contracts,
    protected ReplicaItems $items,
    protected SyncScope $scope,
    protected NotSyncableFlags $flags,
    protected ReportIndex $report,
    protected EntityRepositoryInterface $entityRepository,
    protected MessageSigner $signer,
    protected ClientInterface $httpClient,
    protected LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Sends due events to a replica.
   *
   * @param string $replica_id
   *   The replica.
   * @param int|null $limit
   *   Most events to send; defaults to the configured batch size.
   *
   * @return bool
   *   TRUE if more events are due now and another run should follow.
   */
  public function deliver(string $replica_id, ?int $limit = NULL): bool {
    $lock = 'content_relay_origin:deliver:' . $replica_id;
    if (!$this->lock->acquire($lock, 600)) {
      // Another worker is on it, and will pick up what is due.
      return FALSE;
    }
    try {
      // A contract waiting for acknowledgement goes first, even to a replica
      // that is not receiving events yet: its first contract is what makes it
      // ready.
      $this->contracts->push($replica_id);
      $replica = $this->registry->get($replica_id);
      $contract = $this->contracts->getActive($replica_id);
      if (!$replica || !$replica->receivesDeliveries() || $contract === NULL) {
        return FALSE;
      }
      $now = $this->time->getCurrentTime();
      if ($contract->isPaused() || !$contract->isWithinWindow($now)) {
        return FALSE;
      }
      $limit ??= max(1, (int) ($contract->terms['effective']['batch_size'] ?? $this->settings('batch_size')));
      if ($per_minute = $contract->maxEventsPerMinute()) {
        $limit = min($limit, $per_minute - $this->outbox->countSentSince($replica_id, $now - 60));
      }
      for ($sent = 0; $sent < $limit; $sent++) {
        $row = $this->outbox->nextPending($replica_id);
        if ($row === NULL || (int) $row['next_attempt'] > $this->time->getCurrentTime()) {
          return FALSE;
        }
        if (!$this->send($row, $replica, $contract)) {
          return FALSE;
        }
      }
      return $this->outbox->nextPending($replica_id) !== NULL;
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Sends one row, records the outcome and updates the item's report rows.
   *
   * @return bool
   *   FALSE when delivery to this replica should pause.
   */
  protected function send(array $row, Replica $replica, SubscriptionContract $contract, array &$visited = []): bool {
    try {
      return $this->sendRow($row, $replica, $contract, $visited);
    }
    finally {
      $this->report->refresh($row['entity_type'], $row['entity_uuid']);
    }
  }

  /**
   * Sends one row and records the outcome.
   *
   * @return bool
   *   FALSE when delivery to this replica should pause.
   */
  protected function sendRow(array $row, Replica $replica, SubscriptionContract $contract, array &$visited): bool {
    $id = (int) $row['id'];
    if (!$contract->allowsBundle($row['entity_type'], $row['bundle'])) {
      $this->outbox->update($id, ['status' => EventStatus::SKIPPED, 'result_code' => 'not_in_contract']);
      return TRUE;
    }
    if (in_array($row['operation'], [Operation::Create->value, Operation::Update->value], TRUE)) {
      $blocked = match (TRUE) {
        $this->flags->isBlocked($row['entity_type'], $row['entity_uuid'], $replica->id) => ReportIndex::NOT_SYNCABLE,
        $this->items->isDetached($replica->id, $row['entity_type'], $row['entity_uuid']) => ReportIndex::DETACHED_BY_REPLICA,
        default => NULL,
      };
      if ($blocked !== NULL) {
        $this->outbox->update($id, ['status' => EventStatus::SKIPPED, 'result_code' => $blocked]);
        return TRUE;
      }
      /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
      $entity = $this->entityRepository->loadEntityByUuid($row['entity_type'], $row['entity_uuid']);
      if ($entity instanceof ContentEntityInterface && $this->eventBuilder->hasNoLanguagesFor($entity, $contract)) {
        $this->outbox->update($id, ['status' => EventStatus::SKIPPED, 'result_code' => ReportIndex::NO_LANGUAGES]);
        return TRUE;
      }
    }
    $visited[$row['entity_type'] . ':' . $row['entity_uuid']] = TRUE;
    try {
      $event = $this->eventBuilder->build($row, $contract, $this->outbox->previousSentSequence($replica->id, (int) $row['sequence']));
      if ($event !== NULL && $event->dependencies !== []) {
        $sent = $this->sendDependencies($event, $replica, $contract, $visited);
        if ($sent === NULL) {
          return FALSE;
        }
        if ($sent) {
          // The stream moved on while dependencies went out.
          $event = $this->eventBuilder->build($row, $contract, $this->outbox->previousSentSequence($replica->id, (int) $row['sequence']));
        }
      }
    }
    catch (\Throwable $e) {
      // The origin cannot build what it is supposed to send. Retrying will not
      // help until someone fixes it, and holding the stream would stop every
      // other item, so it is set aside.
      $this->logger->error('Event @event for @type @uuid could not be built for replica @replica: @message', [
        '@event' => $row['event_id'],
        '@type' => $row['entity_type'],
        '@uuid' => $row['entity_uuid'],
        '@replica' => $replica->id,
        '@message' => $e->getMessage(),
      ]);
      $this->outbox->update($id, [
        'status' => EventStatus::DEAD_LETTER,
        'result_code' => 'build_failed',
        'last_response' => mb_substr($e->getMessage(), 0, self::RESPONSE_LIMIT),
      ]);
      return TRUE;
    }
    if ($event === NULL) {
      $this->outbox->update($id, ['status' => EventStatus::SUPERSEDED]);
      return TRUE;
    }
    if (in_array($event->operation, [Operation::Create, Operation::Update], TRUE)) {
      $this->outbox->supersedeLater($row);
    }

    $attempts = (int) $row['attempts'] + 1;
    $fields = [
      'attempts' => $attempts,
      'source_revision' => $event->sourceRevision,
      'first_sent' => $row['first_sent'] ?? $this->time->getCurrentTime(),
    ];
    $envelope = $this->signer->sign($replica->id, 'event', $event->toArray(), $event->sequence);

    try {
      $response = $this->httpClient->request('POST', $replica->endpoint, [
        'json' => $envelope->toArray(),
        'headers' => ['Accept' => 'application/json'],
        'timeout' => (int) $this->settings('timeout'),
        'connect_timeout' => (int) $this->settings('connect_timeout'),
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $fields += [
        'last_http_status' => NULL,
        'result_code' => 'transport_error',
        'last_response' => mb_substr($e->getMessage(), 0, self::RESPONSE_LIMIT),
      ];
      $this->retryOrGiveUp($id, $fields, $attempts, $row, $replica);
      return FALSE;
    }

    $code = $response->getStatusCode();
    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    $result = is_array($decoded) && is_string($decoded['result'] ?? NULL) ? $decoded['result'] : NULL;
    $fields += [
      'last_http_status' => $code,
      'result_code' => $result === NULL ? NULL : mb_substr($result, 0, 64),
      'last_response' => mb_substr($body, 0, self::RESPONSE_LIMIT),
    ];

    if ($code === 409 && $result === 'contract_mismatch') {
      // The replica is missing the contract this event names. Send it again;
      // the event is retried at once, rebuilt under whatever is in force.
      $this->logger->warning('Replica @replica does not have contract version @version; sending it again.', [
        '@replica' => $replica->id,
        '@version' => $contract->version(),
      ]);
      if ($attempts >= max(1, (int) $this->settings('max_attempts'))) {
        $this->outbox->update($id, $fields + ['status' => EventStatus::DEAD_LETTER]);
        return TRUE;
      }
      $this->outbox->update($id, $fields + [
        'status' => EventStatus::FAILED,
        'next_attempt' => $this->time->getCurrentTime(),
      ]);
      $this->contracts->push($replica->id, TRUE);
      return FALSE;
    }
    if ($code === 200 && $result === 'skipped_detached') {
      $this->outbox->update($id, $fields + ['status' => EventStatus::SKIPPED]);
      $this->items->markDetached($replica->id, $event->entityType, $event->entityUuid, $event->bundle, is_string($decoded['details']['detached_by'] ?? NULL) ? $decoded['details']['detached_by'] : NULL, is_int($decoded['details']['detached_at'] ?? NULL) ? $decoded['details']['detached_at'] : NULL);
      $this->registry->recordSuccess($replica->id);
      return TRUE;
    }
    if ($code === 200 || $code === 202 || $code === 409) {
      $status = match ($code) {
        200 => EventStatus::APPLIED,
        202 => EventStatus::ACCEPTED,
        409 => EventStatus::STALE,
      };
      $this->outbox->update($id, $fields + ['status' => $status]);
      $this->items->record($replica->id, $event, match ($code) {
        200 => ReplicaItems::APPLIED,
        default => ReplicaItems::ACCEPTED,
      });
      if ($code === 200 && $event->operation->carriesSnapshot()) {
        // The replica applied the item, so it is not detached there, whatever
        // a lost notice left recorded here.
        $this->items->markAttached($replica->id, $event->entityType, $event->entityUuid);
      }
      $this->registry->recordSuccess($replica->id);
      return TRUE;
    }
    if ($code === 422 && $result === 'apply_failed') {
      $this->outbox->update($id, $fields + ['status' => EventStatus::FAILED_ON_REPLICA]);
      $this->items->record($replica->id, $event, ReplicaItems::FAILED);
      $this->logger->warning('Replica @replica could not apply event @event for @type @uuid: @body', $this->context($row, $replica, $body));
      return TRUE;
    }
    if ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
      $this->outbox->update($id, $fields + ['status' => EventStatus::DEAD_LETTER]);
      $this->logger->error('Replica @replica refused event @event for @type @uuid with HTTP @code: @body', $this->context($row, $replica, $body) + ['@code' => $code]);
      return TRUE;
    }
    $this->retryOrGiveUp($id, $fields, $attempts, $row, $replica);
    return FALSE;
  }

  /**
   * Sends the dependencies of an event that the replica does not have.
   *
   * @return bool|null
   *   TRUE if any were sent, FALSE if none needed sending, NULL if one could
   *   not be delivered and the stream must pause.
   */
  protected function sendDependencies(SyncEvent $event, Replica $replica, SubscriptionContract $contract, array &$visited): ?bool {
    $sent = FALSE;
    foreach ($event->dependencies as $dependency) {
      $key = $dependency['entity_type'] . ':' . $dependency['uuid'];
      if (isset($visited[$key]) || $this->items->isKnown($replica->id, $dependency['entity_type'], $dependency['uuid'])) {
        continue;
      }
      $visited[$key] = TRUE;
      $row = $this->outbox->pendingRowFor($replica->id, $dependency['entity_type'], $dependency['uuid'])
        ?? $this->enqueueDependency($replica, $dependency['entity_type'], $dependency['uuid']);
      if ($row === NULL) {
        continue;
      }
      if (!$this->send($row, $replica, $contract, $visited)) {
        return NULL;
      }
      $sent = TRUE;
    }
    return $sent;
  }

  /**
   * Records an event for a dependency that never had one for this replica.
   *
   * Typically content that existed before the replica subscribed.
   */
  protected function enqueueDependency(Replica $replica, string $entity_type_id, string $uuid): ?array {
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    if (!$entity instanceof ContentEntityInterface || !$this->scope->canSend($entity)) {
      return NULL;
    }
    $ids = $this->outbox->enqueue(Operation::Create, $entity_type_id, $entity->bundle(), $uuid, NULL, [$replica->id]);
    return isset($ids[$replica->id]) ? $this->outbox->load($ids[$replica->id]) : NULL;
  }

  /**
   * Schedules a retry, or dead-letters the row when out of attempts.
   */
  protected function retryOrGiveUp(int $id, array $fields, int $attempts, array $row, Replica $replica): void {
    if ($attempts >= max(1, (int) $this->settings('max_attempts'))) {
      $this->outbox->update($id, $fields + ['status' => EventStatus::DEAD_LETTER]);
      $this->logger->error('Event @event for @type @uuid was dead-lettered for replica @replica after @attempts attempts: @body', $this->context($row, $replica, (string) $fields['last_response']) + ['@attempts' => $attempts]);
      return;
    }
    $this->outbox->update($id, $fields + [
      'status' => EventStatus::FAILED,
      'next_attempt' => $this->time->getCurrentTime() + $this->backoff($attempts),
    ]);
  }

  /**
   * The delay before the next attempt, with jitter.
   *
   * Doubles each attempt from the base, up to the maximum, then picks a
   * random point in the upper half, so replicas recovering from one outage
   * are not all retried in the same second.
   */
  public function backoff(int $attempts): int {
    $base = max(1, (int) $this->settings('backoff_base'));
    $max = max($base, (int) $this->settings('backoff_max'));
    $delay = (int) min($max, $base * 2 ** min(30, $attempts - 1));
    return random_int(intdiv($delay + 1, 2), $delay);
  }

  /**
   * Log placeholders for a row.
   */
  protected function context(array $row, Replica $replica, string $body): array {
    return [
      '@replica' => $replica->id,
      '@event' => $row['event_id'],
      '@type' => $row['entity_type'],
      '@uuid' => $row['entity_uuid'],
      '@body' => mb_substr($body, 0, 500),
    ];
  }

  /**
   * A delivery setting.
   */
  protected function settings(string $key): mixed {
    return $this->configFactory->get('content_relay_origin.settings')->get('delivery.' . $key);
  }

}
