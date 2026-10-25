<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Auth\PullAuthenticatorInterface;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Exception\InvalidEventException;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Talks to the origin on the replica's initiative: notices and reads.
 *
 * Notices about items are signed messages to the origin's inbox. Reads
 * (files, schema, items) carry the replica's short-lived JWT and are answered
 * with signed messages.
 *
 * A notice that cannot be delivered is kept and sent again by cron. Only the
 * latest notice per item is kept: a detach followed by a reattach while the
 * origin was unreachable needs only the reattach.
 */
class OriginClient {

  public const STATE_PENDING = 'content_relay_replica.pending_notices';

  public function __construct(
    protected OriginPeer $origin,
    protected MessageSigner $signer,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected PullAuthenticatorInterface $authenticator,
    protected MessageVerifier $verifier,
  ) {}

  /**
   * Fresh metadata and a download link for a file, from the file endpoint.
   *
   * @return array{uuid: string, uri: string, sha256: string, size: int, download: string, expires: int, url: string|null}
   *   The metadata.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the origin cannot be asked or will not say.
   */
  public function fileInfo(string $uuid): array {
    $info = $this->read('/content-relay/v1/files/' . rawurlencode($uuid), 'file_info');
    if (!is_string($info['sha256'] ?? NULL) || !is_string($info['download'] ?? NULL)) {
      throw new SubscriptionException('The origin\'s answer is not file metadata.');
    }
    return $info;
  }

  /**
   * The origin's schema: the synced fields of the bundles this site may have.
   *
   * @return array<string, array<string, array>>
   *   Bundle descriptions by entity type and bundle; see SchemaDescriber.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  public function schema(): array {
    $body = $this->read('/content-relay/v1/schema', 'schema');
    return is_array($body['bundles'] ?? NULL) ? $body['bundles'] : [];
  }

  /**
   * One item from the origin, as an event built under this site's contract.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the origin cannot be asked, does not have it or refuses.
   */
  public function item(string $entity_type_id, string $uuid): SyncEvent {
    $body = $this->read('/content-relay/v1/items/' . rawurlencode($entity_type_id) . '/' . rawurlencode($uuid), 'item');
    try {
      $event = SyncEvent::fromArray(is_array($body['event'] ?? NULL) ? $body['event'] : []);
    }
    catch (InvalidEventException $e) {
      throw new SubscriptionException('The origin sent a malformed item: ' . $e->getMessage(), 0, $e);
    }
    if ($event->entityType !== $entity_type_id || $event->entityUuid !== $uuid) {
      throw new SubscriptionException('The origin sent a different item.');
    }
    return $event;
  }

  /**
   * A page of the origin's latest hashes for the items of a bundle.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $after
   *   The last UUID of the previous page, or '' for the first.
   * @param int $limit
   *   Items per page.
   *
   * @return array{contract_version: int|null, items: list<array{uuid: string, revision: int, changed: int|null, hash: array, not_syncable: bool}>, next: string|null}
   *   The page, in UUID order, and the cursor for the next one.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  public function hashes(string $entity_type_id, string $bundle, string $after = '', int $limit = 200): array {
    $body = $this->read('/content-relay/v1/hashes?' . http_build_query([
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'after' => $after,
      'limit' => $limit,
    ]), 'hashes');
    if (!is_array($body['items'] ?? NULL) || ($body['entity_type'] ?? NULL) !== $entity_type_id || ($body['bundle'] ?? NULL) !== $bundle) {
      throw new SubscriptionException('The origin\'s answer is not a list of hashes for that bundle.');
    }
    $items = [];
    foreach ($body['items'] as $item) {
      if (!is_string($item['uuid'] ?? NULL) || !is_int($item['revision'] ?? NULL) || !is_array($item['hash'] ?? NULL)) {
        throw new SubscriptionException('The origin sent a malformed hash entry.');
      }
      $items[] = [
        'uuid' => $item['uuid'],
        'revision' => $item['revision'],
        'changed' => is_int($item['changed'] ?? NULL) ? $item['changed'] : NULL,
        'hash' => $item['hash'],
        'not_syncable' => ($item['not_syncable'] ?? FALSE) === TRUE,
      ];
    }
    return [
      'contract_version' => is_int($body['contract_version'] ?? NULL) ? $body['contract_version'] : NULL,
      'items' => $items,
      'next' => is_string($body['next'] ?? NULL) && $body['next'] !== '' ? $body['next'] : NULL,
    ];
  }

  /**
   * What some events in this site's stream were about, and the items now.
   *
   * @param int[] $sequences
   *   Sequence numbers.
   *
   * @return array{answered: int[], events: list<array{sequence: int, operation: string, entity_type: string, bundle: string, uuid: string, current: string}>}
   *   The sequences the origin looked up (fewer than asked when this site is
   *   near its read limit), and the events it knows of among them; see the
   *   origin's ReadController.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  public function stream(array $sequences): array {
    $body = $this->read('/content-relay/v1/stream?' . http_build_query(['sequences' => implode(',', $sequences)]), 'stream');
    $events = [];
    foreach (is_array($body['events'] ?? NULL) ? $body['events'] : [] as $event) {
      if (is_int($event['sequence'] ?? NULL) && is_string($event['entity_type'] ?? NULL) && is_string($event['uuid'] ?? NULL) && is_string($event['current'] ?? NULL)) {
        $events[] = [
          'sequence' => $event['sequence'],
          'operation' => (string) ($event['operation'] ?? ''),
          'entity_type' => $event['entity_type'],
          'bundle' => (string) ($event['bundle'] ?? ''),
          'uuid' => $event['uuid'],
          'current' => $event['current'],
        ];
      }
    }
    $answered = is_array($body['answered'] ?? NULL) ? array_map('intval', $body['answered']) : $sequences;
    return ['answered' => $answered, 'events' => $events];
  }

  /**
   * Reads from the origin with a JWT and verifies the signed answer.
   *
   * @return array
   *   The answer's body.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  protected function read(string $path, string $type): array {
    $origin = $this->origin->get();
    if ($origin === NULL) {
      throw new SubscriptionException('This site is not paired.');
    }
    try {
      $token = $this->authenticator->issue($origin['site_id'], 'sync:read');
      $response = $this->httpClient->request('GET', rtrim($origin['url'], '/') . $path, [
        'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new SubscriptionException(sprintf('The origin could not be reached: %s', $e->getMessage()), 0, $e);
    }
    catch (\RuntimeException $e) {
      throw new SubscriptionException(sprintf('No token could be issued: %s', $e->getMessage()), 0, $e);
    }
    if ($response->getStatusCode() === 429) {
      throw new RateLimitedException(max(1, (int) $response->getHeaderLine('Retry-After') ?: 60));
    }
    $answer = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($answer)) {
      throw new SubscriptionException(sprintf('The origin answered HTTP %d%s.', $response->getStatusCode(), is_array($answer) && is_string($answer['result'] ?? NULL) ? ' (' . $answer['result'] . ')' : ''));
    }
    try {
      $message = $this->verifier->verify($answer);
    }
    catch (VerificationException $e) {
      throw new SubscriptionException(sprintf('The origin\'s answer does not verify: %s', $e->reason), 0, $e);
    }
    if ($message->type !== $type || $message->sender !== $origin['site_id']) {
      throw new SubscriptionException(sprintf('Expected a "%s" answer from the origin.', $type));
    }
    return $message->body;
  }

  /**
   * Sends a notice about an item, or keeps it for later.
   *
   * @param string $type
   *   The message type: detach_notice or attach_notice.
   * @param array $body
   *   With entity_type, uuid and bundle.
   *
   * @return bool
   *   TRUE if the origin recorded it.
   */
  public function notify(string $type, array $body): bool {
    $key = $body['entity_type'] . ':' . $body['uuid'];
    $pending = $this->state->get(self::STATE_PENDING, []);
    unset($pending[$key]);
    if ($this->send($type, $body)) {
      $this->state->set(self::STATE_PENDING, $pending);
      return TRUE;
    }
    $pending[$key] = ['type' => $type, 'body' => $body];
    $this->state->set(self::STATE_PENDING, $pending);
    return FALSE;
  }

  /**
   * Sends notices kept from earlier failures.
   *
   * @return int
   *   How many are still waiting.
   */
  public function sendPending(): int {
    $pending = $this->state->get(self::STATE_PENDING, []);
    foreach ($pending as $key => $notice) {
      if ($this->send($notice['type'], $notice['body'])) {
        unset($pending[$key]);
      }
    }
    $this->state->set(self::STATE_PENDING, $pending);
    return count($pending);
  }

  /**
   * Notices waiting to be sent, by entity type and UUID.
   *
   * @return array<string, array{type: string, body: array}>
   *   Notices.
   */
  public function getPending(): array {
    return $this->state->get(self::STATE_PENDING, []);
  }

  /**
   * Signs and posts one message.
   */
  protected function send(string $type, array $body): bool {
    $origin = $this->origin->get();
    if ($origin === NULL) {
      return FALSE;
    }
    try {
      $envelope = $this->signer->sign($origin['site_id'], $type, $body);
    }
    catch (\RuntimeException $e) {
      // No usable signing key. Kept, and sent once there is one.
      $this->logger->error('A @type could not be signed: @message', ['@type' => $type, '@message' => $e->getMessage()]);
      return FALSE;
    }
    try {
      $response = $this->httpClient->request('POST', $origin['url'] . '/content-relay/v1/messages', [
        'json' => $envelope->toArray(),
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Sending a @type for @item to the origin failed: @message', [
        '@type' => $type,
        '@item' => $body['entity_type'] . ' ' . $body['uuid'],
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    if ($response->getStatusCode() !== 200) {
      $this->logger->warning('The origin refused a @type for @item with HTTP @code.', [
        '@type' => $type,
        '@item' => $body['entity_type'] . ' ' . $body['uuid'],
        '@code' => $response->getStatusCode(),
      ]);
      return FALSE;
    }
    return TRUE;
  }

}
