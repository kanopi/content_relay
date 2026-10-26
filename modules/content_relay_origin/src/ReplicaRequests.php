<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Auth\AuthenticationException;
use Drupal\content_relay\Auth\PullAuthenticatorInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Http\IpAllowlist;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates read requests from replicas and signs the answers.
 *
 * Replicas read from the origin (files, schema, items) with a short-lived JWT
 * they issue themselves; see JwtAuthenticator. Answers are signed messages to
 * that replica, sealed when its contract is encrypted.
 *
 * Each replica has a budget of items it may read per window (read_limit in
 * the settings), counted over items, hash listings and stream lookups, so a
 * large reconciliation, or many replicas at once, cannot swamp the origin. A
 * replica over its budget is answered 429 with Retry-After, and keeps the
 * rest of its work queued until then. Files are not counted: they have their
 * own download queue on the replica.
 */
class ReplicaRequests {

  /**
   * The scope read requests must carry.
   */
  public const SCOPE = 'sync:read';

  public function __construct(
    protected PullAuthenticatorInterface $authenticator,
    protected ReplicaRegistry $registry,
    protected MessageSigner $signer,
    protected LoggerInterface $logger,
    protected ConfigFactoryInterface $configFactory,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected TimeInterface $time,
    protected SendingSettings $sending,
  ) {}

  /**
   * How many more items a replica may read in the current window.
   *
   * @return int
   *   The number, or PHP_INT_MAX without a limit.
   */
  public function remaining(string $replica_id): int {
    [$limit, $window] = $this->limit($replica_id);
    if ($limit <= 0) {
      return PHP_INT_MAX;
    }
    $used = $this->keyValueExpirable->get('content_relay_origin.read_budget')->get($replica_id);
    $now = $this->time->getCurrentTime();
    if (!is_array($used) || $used['start'] + $window <= $now) {
      return $limit;
    }
    return max(0, $limit - $used['count']);
  }

  /**
   * Counts items read by a replica against its budget.
   */
  public function consume(string $replica_id, int $count): void {
    [$limit, $window] = $this->limit($replica_id);
    if ($limit <= 0 || $count <= 0) {
      return;
    }
    $store = $this->keyValueExpirable->get('content_relay_origin.read_budget');
    $used = $store->get($replica_id);
    $now = $this->time->getCurrentTime();
    if (!is_array($used) || $used['start'] + $window <= $now) {
      $used = ['start' => $now, 'count' => 0];
    }
    $used['count'] += $count;
    $store->setWithExpire($replica_id, $used, max(1, $used['start'] + $window - $now));
  }

  /**
   * The refusal for a replica over its read budget.
   */
  public function overBudget(string $replica_id): JsonResponse {
    [, $window] = $this->limit($replica_id);
    $used = $this->keyValueExpirable->get('content_relay_origin.read_budget')->get($replica_id);
    $retry = is_array($used) ? max(1, $used['start'] + $window - $this->time->getCurrentTime()) : $window;
    $response = self::refuse(429, 'rate_limited', 'This replica has read as much as it may for now.');
    $response->headers->set('Retry-After', (string) $retry);
    return $response;
  }

  /**
   * The read limit: items per window, and the window in seconds.
   *
   * @return array{0: int, 1: int}
   *   Items (0 for no limit) and seconds.
   */
  protected function limit(string $replica_id): array {
    $items = (int) ($this->sending->get('read_limit.items', $replica_id) ?? 0);
    $window = max(1, (int) ($this->sending->get('read_limit.window', $replica_id) ?? 60));
    return [$items, $window];
  }

  /**
   * The replica a request comes from, or a refusal to send instead.
   */
  public function authenticate(Request $request): string|JsonResponse {
    $header = (string) $request->headers->get('Authorization', '');
    if (!str_starts_with($header, 'Bearer ')) {
      return self::refuse(401, 'unauthenticated', 'A bearer token is required.');
    }
    try {
      $replica_id = $this->authenticator->authenticate(substr($header, 7), self::SCOPE);
    }
    catch (AuthenticationException $e) {
      $this->logger->warning('Refused a read request from @ip: @reason', [
        '@ip' => $request->getClientIp(),
        '@reason' => $e->reason,
      ]);
      return self::refuse(401, $e->reason, $e->getMessage());
    }
    $replica = $this->registry->get($replica_id);
    if ($replica === NULL || !$replica->receivesEvents()) {
      return self::refuse(403, 'not_receiving', 'This replica does not receive from this origin.');
    }
    if (!IpAllowlist::allows($request->getClientIp(), $replica->allowedIps)) {
      $this->logger->warning('Refused a read request for replica @replica from @ip, which is not on its allowlist.', [
        '@replica' => $replica_id,
        '@ip' => $request->getClientIp(),
      ]);
      return self::refuse(403, 'address_not_allowed', 'Requests for this replica are not accepted from this address.');
    }
    return $replica_id;
  }

  /**
   * A signed answer to a replica.
   */
  public function answer(string $replica_id, string $type, array $body): JsonResponse {
    return new JsonResponse($this->signer->sign($replica_id, $type, $body)->toArray(), 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * A refusal.
   */
  public static function refuse(int $status, string $result, string $message): JsonResponse {
    return new JsonResponse(['result' => $result, 'message' => $message], $status, ['Cache-Control' => 'no-store']);
  }

}
