<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends signed notices about items to the origin's inbox.
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
  ) {}

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
