<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;

/**
 * Events this replica received and could not apply.
 *
 * Each row keeps the full event, so it can be retried without asking the
 * origin, and enough about the failure to show it in the report. One row per
 * event: failing again updates the row and counts the attempt.
 *
 * Failure classes:
 * - transient: worth retrying (deadlock, lock timeout, database gone away).
 *   Retried locally with backoff, then treated as permanent.
 * - permanent: retrying the same event will fail the same way (validation,
 *   unknown field, an exception in a hook). Waits for a person.
 * - dependency: references an item that is not here yet. Retried
 *   automatically when that item arrives.
 */
class FailedEventStore {

  public const TABLE = 'content_relay_failed_event';
  public const DEPENDENCY_TABLE = 'content_relay_failed_event_dependency';
  public const RETRY_QUEUE = 'content_relay_replica_retry';

  public const OPEN = 'open';
  public const RESOLVED = 'resolved';
  public const SUPERSEDED = 'superseded';
  public const DISMISSED = 'dismissed';

  public const TRANSIENT = 'transient';
  public const PERMANENT = 'permanent';
  public const DEPENDENCY = 'dependency';

  public function __construct(
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected ContractStore $contracts,
    protected KeyStore $keyStore,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected ReceivingSettings $receiving,
  ) {}

  /**
   * Cache tag of anything showing whether failures are open.
   */
  public const CACHE_TAG = 'content_relay_failures';

  /**
   * Prefix of a payload sealed to this site's encryption key.
   */
  protected const SEALED = 'sealed:';

  /**
   * Records a failure, or another attempt at an already failed event.
   *
   * @param \Drupal\content_relay\Event\SyncEvent $event
   *   The event.
   * @param string $source_site
   *   Who sent it.
   * @param string $class
   *   One of the class constants TRANSIENT, PERMANENT or DEPENDENCY.
   * @param string $code
   *   A short machine-readable error code.
   * @param string $message
   *   What went wrong, in full, for this site's administrators.
   * @param string|null $error_class
   *   The exception class, when there was one.
   * @param array $violations
   *   Field violations: each with field, langcode and message.
   * @param array $dependencies
   *   Missing items: each with entity_type, uuid and field.
   *
   * @return array
   *   The stored row.
   */
  public function record(SyncEvent $event, string $source_site, string $class, string $code, string $message, ?string $error_class = NULL, array $violations = [], array $dependencies = []): array {
    $now = $this->time->getCurrentTime();
    $existing = $this->getByEvent($event->eventId);
    $attempts = $existing ? (int) $existing['attempts'] + 1 : 1;
    $fields = [
      'status' => self::OPEN,
      'failure_class' => $class,
      'error_code' => mb_substr($code, 0, 64),
      'error_class' => $error_class,
      'message' => $message,
      'violations' => json_encode($violations, \JSON_THROW_ON_ERROR),
      'attempts' => $attempts,
      'next_retry' => $class === self::TRANSIENT ? $now + $this->retryDelay($attempts) : NULL,
      'last_failed' => $now,
      'resolved' => NULL,
    ];
    if ($existing) {
      $this->database->update(self::TABLE)->fields($fields)->condition('id', $existing['id'])->execute();
      $id = (int) $existing['id'];
    }
    else {
      $id = (int) $this->database->insert(self::TABLE)->fields($fields + [
        'event_id' => $event->eventId,
        'source_site' => $source_site,
        'entity_type' => $event->entityType,
        'bundle' => $event->bundle,
        'uuid' => $event->entityUuid,
        'label' => $this->labelFromSnapshot($event),
        'operation' => $event->operation->value,
        'contract_version' => $event->contractVersion,
        'source_revision' => $event->sourceRevision,
        'source_hash' => $event->hashes?->entity,
        'payload' => $this->storePayload(json_encode($event->toArray(), \JSON_THROW_ON_ERROR)),
        'first_failed' => $now,
      ])->execute();
    }

    $this->database->delete(self::DEPENDENCY_TABLE)->condition('failure_id', $id)->execute();
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    foreach ($dependencies as $dependency) {
      $this->database->merge(self::DEPENDENCY_TABLE)
        ->keys(['failure_id' => $id, 'entity_type' => $dependency['entity_type'], 'uuid' => $dependency['uuid']])
        ->fields(['field' => $dependency['field'] ?? ''])
        ->execute();
    }
    return $this->load($id);
  }

  /**
   * Whether a transient failure has used up its local retries.
   */
  public function isRetryLimitReached(int $attempts): bool {
    return $attempts > max(0, (int) $this->receiving->get('failures.transient_retry_limit'));
  }

  /**
   * A failure by ID.
   */
  public function load(int $id): ?array {
    return $this->database->select(self::TABLE, 'f')->fields('f')->condition('id', $id)->execute()->fetchAssoc() ?: NULL;
  }

  /**
   * The failure recorded for an event.
   */
  public function getByEvent(string $event_id): ?array {
    return $this->database->select(self::TABLE, 'f')->fields('f')->condition('event_id', $event_id)->execute()->fetchAssoc() ?: NULL;
  }

  /**
   * The stored event of a failure.
   *
   * @throws \RuntimeException
   *   When it was sealed to a key this site no longer has.
   */
  public function getEvent(array $failure): SyncEvent {
    return SyncEvent::fromArray(json_decode($this->readPayload((string) $failure['payload']), TRUE, 512, \JSON_THROW_ON_ERROR));
  }

  /**
   * The payload as stored: sealed to this site's own key when encrypted.
   *
   * When the contract encrypts messages, what arrived encrypted is not kept
   * in the clear either. Only this site's encryption key opens it.
   */
  protected function storePayload(string $json): string {
    if (($this->contracts->getCurrent()?->encryption() ?? 'none') === 'none') {
      return $json;
    }
    try {
      $key = $this->keyStore->getPrimary(KeyPurpose::Encryption);
    }
    catch (\RuntimeException) {
      return $json;
    }
    return self::SEALED . $key->id() . ':' . base64_encode(KeyPair::seal($json, $key->publicKey));
  }

  /**
   * A stored payload, opened if it was sealed.
   *
   * @throws \RuntimeException
   *   When the key it was sealed to is gone.
   */
  protected function readPayload(string $payload): string {
    if (!str_starts_with($payload, self::SEALED)) {
      return $payload;
    }
    [$key_id, $sealed] = explode(':', substr($payload, strlen(self::SEALED)), 2) + [1 => ''];
    $json = $this->keyStore->get(KeyPurpose::Encryption, $key_id)?->open((string) base64_decode($sealed, TRUE));
    if ($json === NULL) {
      throw new \RuntimeException(sprintf('The stored event is sealed to encryption key %s, which this site no longer has. Pull the item fresh instead.', $key_id));
    }
    return $json;
  }

  /**
   * Open failures for an item.
   *
   * @return array[]
   *   Rows, oldest first.
   */
  public function getOpenForEntity(string $entity_type_id, string $uuid): array {
    $rows = $this->database->select(self::TABLE, 'f')
      ->fields('f')
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->condition('status', self::OPEN)
      ->orderBy('source_revision')
      ->execute()
      ->fetchAll();
    return array_map(fn ($row) => (array) $row, $rows);
  }

  /**
   * Items a failure is waiting for.
   *
   * @return array[]
   *   Each with entity_type, uuid and field.
   */
  public function getDependencies(int $failure_id): array {
    $rows = $this->database->select(self::DEPENDENCY_TABLE, 'd')
      ->fields('d', ['entity_type', 'uuid', 'field'])
      ->condition('failure_id', $failure_id)
      ->execute()
      ->fetchAll();
    return array_map(fn ($row) => (array) $row, $rows);
  }

  /**
   * Marks an event's failure resolved, after it applied.
   */
  public function resolve(string $event_id): void {
    $failure = $this->getByEvent($event_id);
    if ($failure) {
      $this->close((int) $failure['id'], self::RESOLVED);
    }
  }

  /**
   * Marks other open failures for an item superseded by a newer success.
   */
  public function supersede(string $entity_type_id, string $uuid, string $except_event_id): void {
    foreach ($this->getOpenForEntity($entity_type_id, $uuid) as $failure) {
      if ($failure['event_id'] !== $except_event_id) {
        $this->close((int) $failure['id'], self::SUPERSEDED);
      }
    }
  }

  /**
   * Records that someone looked at a failure and decided to leave it.
   */
  public function dismiss(int $id, int $uid, string $reason): void {
    $this->database->update(self::TABLE)
      ->fields(['dismissed_by' => $uid, 'dismiss_reason' => $reason])
      ->condition('id', $id)
      ->execute();
    $this->close($id, self::DISMISSED);
  }

  /**
   * Queues retries of failures waiting for an item that has now arrived.
   *
   * @return int[]
   *   The failure IDs queued.
   */
  public function retryDependents(string $entity_type_id, string $uuid): array {
    $query = $this->database->select(self::DEPENDENCY_TABLE, 'd');
    $query->join(self::TABLE, 'f', 'f.id = d.failure_id');
    $ids = $query->fields('d', ['failure_id'])
      ->condition('d.entity_type', $entity_type_id)
      ->condition('d.uuid', $uuid)
      ->condition('f.status', self::OPEN)
      ->execute()
      ->fetchCol();
    $ids = array_map('intval', $ids);
    $this->queueRetries($ids);
    return $ids;
  }

  /**
   * Queues retries of transient failures that are due.
   */
  public function scheduleDueRetries(): void {
    $ids = $this->database->select(self::TABLE, 'f')
      ->fields('f', ['id'])
      ->condition('status', self::OPEN)
      ->condition('failure_class', self::TRANSIENT)
      ->condition('next_retry', $this->time->getCurrentTime(), '<=')
      ->execute()
      ->fetchCol();
    $this->queueRetries(array_map('intval', $ids));
  }

  /**
   * Queues retries.
   *
   * @param int[] $ids
   *   Failure IDs.
   */
  public function queueRetries(array $ids): void {
    $queue = $this->queueFactory->get(self::RETRY_QUEUE);
    foreach ($ids as $id) {
      $queue->createItem(['failure' => $id]);
    }
  }

  /**
   * Failures matching filters, newest first.
   *
   * @param array $filters
   *   Any of: status (default open), entity_type, bundle, failure_class,
   *   error_code.
   * @param int $limit
   *   Most rows; 0 for all.
   *
   * @return array[]
   *   Rows.
   */
  public function find(array $filters = [], int $limit = 0): array {
    $query = $this->database->select(self::TABLE, 'f')
      ->fields('f', [
        'id',
        'event_id',
        'entity_type',
        'bundle',
        'uuid',
        'label',
        'operation',
        'status',
        'failure_class',
        'error_code',
        'message',
        'attempts',
        'last_failed',
        'dismiss_reason',
      ])
      ->condition('status', $filters['status'] ?? self::OPEN)
      ->orderBy('last_failed', 'DESC')
      ->orderBy('id', 'DESC');
    foreach (['entity_type', 'bundle', 'failure_class', 'error_code'] as $key) {
      if (($filters[$key] ?? '') !== '') {
        $query->condition($key, $filters[$key]);
      }
    }
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    return array_map(fn ($row) => (array) $row, $query->execute()->fetchAll());
  }

  /**
   * How many failures are waiting for attention.
   */
  public function countOpen(): int {
    return (int) $this->database->select(self::TABLE, 'f')
      ->condition('status', self::OPEN)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Closes a failure.
   */
  protected function close(int $id, string $status): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => $status, 'resolved' => $this->time->getCurrentTime(), 'next_retry' => NULL])
      ->condition('id', $id)
      ->execute();
    $this->database->delete(self::DEPENDENCY_TABLE)->condition('failure_id', $id)->execute();
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * The delay before retrying a transient failure, by attempt.
   */
  protected function retryDelay(int $attempts): int {
    $settings = $this->receiving;
    $base = max(1, (int) $settings->get('failures.retry_base'));
    $max = max($base, (int) $settings->get('failures.retry_max'));
    return (int) min($max, $base * 2 ** min(20, $attempts - 1));
  }

  /**
   * A label for an item that may not exist yet, from its snapshot.
   */
  protected function labelFromSnapshot(SyncEvent $event): ?string {
    $snapshot = $event->snapshot;
    if ($snapshot === NULL) {
      return NULL;
    }
    $fields = $snapshot['shared'] + ($snapshot['translations'][$snapshot['default_langcode']] ?? []);
    foreach (['title', 'name', 'label', 'info', 'subject'] as $name) {
      if (isset($fields[$name][0]['value']) && is_string($fields[$name][0]['value'])) {
        return mb_substr($fields[$name][0]['value'], 0, 255);
      }
    }
    return NULL;
  }

}
