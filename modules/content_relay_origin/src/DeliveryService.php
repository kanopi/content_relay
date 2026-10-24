<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Event\Operation;
use Drupal\Core\Config\ConfigFactoryInterface;
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
 * - 422 with result "apply_failed": the replica could not save a valid event.
 *   The replica keeps it and owns the retries; the origin records the failure
 *   and does not resend.
 * - 408, 429 and 5xx, timeouts and connection failures: retried with
 *   exponential backoff and jitter, then dead-lettered.
 * - Any other 4xx: dead-lettered at once. Resending the same thing would get
 *   the same answer.
 *
 * Only one worker delivers to a replica at a time, and a retrying event holds
 * back everything behind it, so a replica always receives its stream in
 * order.
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
    $replica = $this->registry->get($replica_id);
    if (!$replica || !$replica->receivesDeliveries()) {
      return FALSE;
    }
    $lock = 'content_relay_origin:deliver:' . $replica_id;
    if (!$this->lock->acquire($lock, 600)) {
      // Another worker is on it, and will pick up what is due.
      return FALSE;
    }
    try {
      $limit ??= max(1, (int) $this->settings('batch_size'));
      for ($sent = 0; $sent < $limit; $sent++) {
        $row = $this->outbox->nextPending($replica_id);
        if ($row === NULL || (int) $row['next_attempt'] > $this->time->getCurrentTime()) {
          return FALSE;
        }
        if (!$this->send($row, $replica)) {
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
   * Sends one row and records the outcome.
   *
   * @return bool
   *   FALSE when delivery to this replica should pause.
   */
  protected function send(array $row, Replica $replica): bool {
    $id = (int) $row['id'];
    try {
      $event = $this->eventBuilder->build($row, $replica, $this->outbox->previousSentSequence($replica->id, (int) $row['sequence']));
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

    if ($code === 200 || $code === 202 || $code === 409) {
      $status = match ($code) {
        200 => EventStatus::APPLIED,
        202 => EventStatus::ACCEPTED,
        409 => EventStatus::STALE,
      };
      $this->outbox->update($id, $fields + ['status' => $status]);
      $this->registry->recordSuccess($replica->id);
      return TRUE;
    }
    if ($code === 422 && $result === 'apply_failed') {
      $this->outbox->update($id, $fields + ['status' => EventStatus::FAILED_ON_REPLICA]);
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
