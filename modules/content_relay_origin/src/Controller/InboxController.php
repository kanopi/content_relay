<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Http\IpAllowlist;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\PeerKeys;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\ItemSyncControl;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\ReplicaRegistry;
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
 * - subscription_update: the replica proposes new terms, and announces its
 *   current public keys. The answer is the resulting contract, signed, which
 *   the replica then acknowledges. A key the replica no longer lists is no
 *   longer accepted from it.
 * - detach_notice and attach_notice: the replica detached or reattached an
 *   item. A reattach queues the item for it again.
 */
final class InboxController implements ContainerInjectionInterface {

  public function __construct(
    private readonly MessageVerifier $verifier,
    private readonly ContractManager $contracts,
    private readonly ItemSyncControl $control,
    private readonly ReplicaRegistry $registry,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay.message_verifier'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('content_relay_origin.item_sync_control'),
      $container->get('content_relay_origin.replica_registry'),
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
    if (!IpAllowlist::allows($request->getClientIp(), $this->registry->get($message->sender)->allowedIps ?? [])) {
      $this->logger->warning('Refused a message from replica @replica at @ip, which is not on its allowlist.', [
        '@replica' => $message->sender,
        '@ip' => $request->getClientIp(),
      ]);
      return new JsonResponse([
        'result' => 'address_not_allowed',
        'message' => 'Messages from this replica are not accepted from this address.',
      ], 403);
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
        if (isset($message->body['keys'])) {
          try {
            $signing = PeerKeys::check($message->body['keys']['signing'] ?? NULL, KeyPurpose::Signing);
            $encryption = PeerKeys::check($message->body['keys']['encryption'] ?? [], KeyPurpose::Encryption);
          }
          catch (\InvalidArgumentException $e) {
            return new JsonResponse(['result' => 'malformed', 'message' => $e->getMessage()], 400);
          }
          if ($signing === []) {
            return new JsonResponse(['result' => 'malformed', 'message' => 'At least one signing key is required.'], 400);
          }
          $this->registry->setKeys($message->sender, $signing, $encryption);
        }
        $contract = $this->contracts->propose($message->sender, $terms, NULL, 'replica');
        return new JsonResponse(['result' => 'contract', 'contract' => $this->contracts->sign($contract)->toArray()]);

      case 'detach_notice':
      case 'attach_notice':
        $body = $message->body;
        foreach (['entity_type', 'uuid', 'bundle'] as $key) {
          if (!is_string($body[$key] ?? NULL) || $body[$key] === '') {
            return new JsonResponse([
              'result' => 'malformed',
              'message' => sprintf('A %s needs %s.', $message->type, $key),
            ], 400);
          }
        }
        $by = is_string($body['by'] ?? NULL) ? $body['by'] : NULL;
        // Scoped to one translation.
        if (is_string($body['langcode'] ?? NULL) && $body['langcode'] !== '') {
          return new JsonResponse([
            'result' => 'recorded',
            'queued' => $this->control->replicaTranslationDetached($message->sender, $body['entity_type'], $body['uuid'], $body['bundle'], $body['langcode'], $message->type === 'detach_notice', $by),
          ]);
        }
        if ($message->type === 'detach_notice') {
          $this->control->replicaDetached($message->sender, $body['entity_type'], $body['uuid'], $body['bundle'], $by, is_int($body['at'] ?? NULL) ? $body['at'] : NULL);
          return new JsonResponse(['result' => 'recorded']);
        }
        return new JsonResponse([
          'result' => 'recorded',
          'queued' => $this->control->replicaAttached($message->sender, $body['entity_type'], $body['uuid'], $by),
        ]);

      case 'unpair_notice':
        // The replica no longer receives: as if revoked here, so it can pair
        // again with a new code.
        $this->registry->setStatus($message->sender, Replica::REVOKED);
        $this->logger->notice('Replica @replica unpaired; it is revoked here.', ['@replica' => $message->sender]);
        return new JsonResponse(['result' => 'revoked']);

      default:
        return new JsonResponse([
          'result' => 'unsupported_type',
          'message' => sprintf('This origin does not accept "%s" messages.', $message->type),
        ], 400);
    }
  }

}
