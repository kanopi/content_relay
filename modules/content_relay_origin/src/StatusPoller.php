<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Finds out how events a replica accepted for later (202) ended.
 *
 * Asks each replica, in batches, through its status endpoint: a signed
 * status_request answered with a signed status message. No callbacks from
 * replicas are needed. For each event:
 *
 * - final: recorded as if the replica had answered at once (applied, stale,
 *   failed on replica, dead letter), with what the replica now has;
 * - pending: asked again next time;
 * - unknown: the replica does not have it (lost, or restored from a backup),
 *   so it is sent again.
 *
 * The answer also carries the replica's contract version; when it differs
 * from the one in force here, the contract is sent again.
 */
class StatusPoller {

  public function __construct(
    protected Outbox $outbox,
    protected ReplicaRegistry $registry,
    protected ReplicaItems $items,
    protected HashStore $hashStore,
    protected ContractManager $contracts,
    protected MessageSigner $signer,
    protected MessageVerifier $verifier,
    protected ReportIndex $report,
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected SendingSettings $sending,
  ) {}

  /**
   * Polls every replica with accepted events. For cron.
   *
   * @return array<string, array<string, int>>
   *   By replica, how many events ended in each way.
   */
  public function pollAll(): array {
    $results = [];
    foreach ($this->outbox->replicasWithAcceptedRows() as $replica_id) {
      $results[$replica_id] = $this->poll($replica_id);
    }
    return $results;
  }

  /**
   * Asks one replica about its accepted events.
   *
   * @return array<string, int>
   *   How many ended in each way: applied, stale, failed_on_replica,
   *   dead_letter, pending, resent. Empty when the replica could not be
   *   asked.
   */
  public function poll(string $replica_id): array {
    $replica = $this->registry->get($replica_id);
    if ($replica === NULL || !$replica->receivesDeliveries()) {
      return [];
    }
    $limit = max(1, (int) ($this->configFactory->get('content_relay_origin.settings')->get('delivery.status_batch') ?: 200));
    $rows = [];
    foreach ($this->outbox->acceptedRows($replica_id, $limit) as $row) {
      $rows[$row['event_id']] = $row;
    }
    if ($rows === []) {
      return [];
    }
    $report = $this->ask($replica, array_keys($rows));
    if ($report === NULL) {
      return [];
    }

    $counts = [];
    foreach ($rows as $event_id => $row) {
      $status = $report['events'][$event_id] ?? ['state' => 'unknown'];
      $outcome = $this->settle($row, is_array($status) ? $status : ['state' => 'unknown']);
      $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
      if ($outcome !== 'pending') {
        $this->report->refresh($row['entity_type'], $row['entity_uuid']);
      }
    }

    $active = $this->contracts->getActive($replica_id)?->version();
    if ($active !== NULL && isset($report['contract_version']) && $report['contract_version'] !== $active) {
      $this->logger->warning('Replica @replica reports contract version @theirs; version @ours is in force. Sending it again.', [
        '@replica' => $replica_id,
        '@theirs' => $report['contract_version'],
        '@ours' => $active,
      ]);
      $this->contracts->push($replica_id, TRUE);
    }
    return $counts;
  }

  /**
   * Records how one event ended.
   *
   * @return string
   *   The outcome.
   */
  protected function settle(array $row, array $status): string {
    $id = (int) $row['id'];
    if (($status['state'] ?? NULL) === 'pending') {
      return 'pending';
    }
    if (($status['state'] ?? NULL) !== 'final') {
      // Sent again at once, in its place in the stream.
      $this->outbox->update($id, [
        'status' => EventStatus::FAILED,
        'next_attempt' => $this->time->getCurrentTime(),
        'result_code' => 'unknown_to_replica',
      ]);
      $this->logger->warning('Replica @replica has no record of accepted event @event; sending it again.', [
        '@replica' => $row['replica_id'],
        '@event' => $row['event_id'],
      ]);
      return 'resent';
    }
    $code = (int) ($status['http_status'] ?? 0);
    $fields = [
      'last_http_status' => $code,
      'result_code' => is_string($status['result'] ?? NULL) ? mb_substr($status['result'], 0, 64) : NULL,
      'last_response' => mb_substr((string) json_encode($status), 0, DeliveryService::RESPONSE_LIMIT),
    ];
    [$event_status, $item_status] = match (TRUE) {
      $code === 200 => [EventStatus::APPLIED, ReplicaItems::APPLIED],
      $code === 409 => [EventStatus::STALE, NULL],
      $code === 422 => [EventStatus::FAILED_ON_REPLICA, ReplicaItems::FAILED],
      default => [EventStatus::DEAD_LETTER, NULL],
    };
    $this->outbox->update($id, $fields + ['status' => $event_status]);
    if ($item_status !== NULL) {
      $hashes = $row['source_revision'] === NULL ? NULL : $this->hashStore->forRevision($row['entity_type'], $row['entity_uuid'], (int) $row['source_revision']);
      $this->items->recordSettled($row, $item_status, $hashes);
    }
    return $event_status;
  }

  /**
   * Sends a status request and verifies the answer.
   *
   * @return array|null
   *   The status report, or NULL when it could not be had.
   */
  protected function ask(Replica $replica, array $event_ids): ?array {
    try {
      $response = $this->httpClient->request('POST', $replica->endpoint, [
        'json' => $this->signer->sign($replica->id, 'status_request', ['event_ids' => $event_ids])->toArray(),
        'headers' => ['Accept' => 'application/json'],
        'timeout' => (int) ($this->sending->get('delivery.timeout', $replica->id) ?: 30),
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Replica @replica could not be asked about accepted events: @message', [
        '@replica' => $replica->id,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($body['status'] ?? NULL)) {
      $this->logger->warning('Replica @replica answered a status request with HTTP @code.', [
        '@replica' => $replica->id,
        '@code' => $response->getStatusCode(),
      ]);
      return NULL;
    }
    try {
      $message = $this->verifier->verify($body['status']);
    }
    catch (VerificationException $e) {
      $this->logger->error('Replica @replica sent a status answer that does not verify: @reason', [
        '@replica' => $replica->id,
        '@reason' => $e->reason,
      ]);
      return NULL;
    }
    if ($message->type !== 'status' || $message->sender !== $replica->id || !is_array($message->body['events'] ?? NULL)) {
      return NULL;
    }
    return $message->body;
  }

}
