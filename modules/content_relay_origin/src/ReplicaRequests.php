<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Auth\AuthenticationException;
use Drupal\content_relay\Auth\PullAuthenticatorInterface;
use Drupal\content_relay\Crypto\MessageSigner;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authenticates read requests from replicas and signs the answers.
 *
 * Replicas read from the origin (files, schema, items) with a short-lived JWT
 * they issue themselves; see JwtAuthenticator. Answers are signed messages to
 * that replica, sealed when its contract is encrypted.
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
  ) {}

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
