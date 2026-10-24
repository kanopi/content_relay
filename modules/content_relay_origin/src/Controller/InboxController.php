<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay_origin\ContractManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives signed messages from paired replicas.
 *
 * Only replicas in the registry can be verified, so nothing else gets past
 * the signature check.
 *
 * - contract_ack: the replica stored a contract version.
 * - subscription_update: the replica proposes new terms. The answer is the
 *   resulting contract, signed, which the replica then acknowledges.
 */
final class InboxController implements ContainerInjectionInterface {

  public function __construct(
    private readonly MessageVerifier $verifier,
    private readonly ContractManager $contracts,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay.message_verifier'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('logger.channel.content_relay_origin'),
    );
  }

  /**
   * Handles a posted message.
   */
  public function receive(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['result' => 'malformed', 'message' => 'The request body is not a JSON object.'], 400);
    }
    try {
      $message = $this->verifier->verify($data);
    }
    catch (VerificationException $e) {
      $this->logger->warning('Refused a message from @ip: @reason', [
        '@ip' => $request->getClientIp(),
        '@reason' => $e->reason,
      ]);
      return new JsonResponse(['result' => $e->reason, 'message' => $e->getMessage()], $e->reason === VerificationException::MALFORMED ? 400 : 403);
    }

    switch ($message->type) {
      case 'contract_ack':
        return $this->contracts->handleAcknowledgement($message)
          ? new JsonResponse(['result' => 'acknowledged'])
          : new JsonResponse([
            'result' => 'unknown_version',
            'message' => 'That contract version is not pending or active.',
          ], 409);

      case 'subscription_update':
        $terms = $message->body['replica_terms'] ?? NULL;
        if (!is_array($terms)) {
          return new JsonResponse(['result' => 'malformed', 'message' => 'A subscription update needs replica_terms.'], 400);
        }
        $contract = $this->contracts->propose($message->sender, $terms, NULL, 'replica');
        return new JsonResponse(['result' => 'contract', 'contract' => $this->contracts->sign($contract)->toArray()]);

      default:
        return new JsonResponse([
          'result' => 'unsupported_type',
          'message' => sprintf('This origin does not accept "%s" messages.', $message->type),
        ], 400);
    }
  }

}
