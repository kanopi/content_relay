<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Auth\PullAuthenticatorInterface;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Talks to the origin on the replica's initiative: notices and file requests.
 *
 * Notices about items are signed messages to the origin's inbox; file
 * requests carry the replica's short-lived JWT.
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
    $origin = $this->origin->get();
    if ($origin === NULL) {
      throw new SubscriptionException('This site is not paired.');
    }
    try {
      $token = $this->authenticator->issue($origin['site_id'], 'sync:read');
      $response = $this->httpClient->request('GET', rtrim($origin['url'], '/') . '/content-relay/v1/files/' . rawurlencode($uuid), [
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
    $answer = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($answer)) {
      throw new SubscriptionException(sprintf('The origin answered HTTP %d%s.', $response->getStatusCode(), is_array($answer) && is_string($answer['result'] ?? NULL) ? ' (' . $answer['result'] . ')' : ''));
    }
    // A signed message: it can carry the file's key.
    try {
      $message = $this->verifier->verify($answer);
    }
    catch (VerificationException $e) {
      throw new SubscriptionException(sprintf('The origin\'s answer does not verify: %s', $e->reason), 0, $e);
    }
    $info = $message->body;
    if ($message->type !== 'file_info' || $message->sender !== $origin['site_id'] || !is_string($info['sha256'] ?? NULL) || !is_string($info['download'] ?? NULL)) {
      throw new SubscriptionException('The origin\'s answer is not file metadata.');
    }
    return $info;
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
