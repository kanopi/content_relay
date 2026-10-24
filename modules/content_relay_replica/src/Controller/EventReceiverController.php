<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Controller;

use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Exception\InvalidEventException;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\StreamTracker;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives events from the origin.
 *
 * Refusals before an event is identified (malformed, unsigned, wrong sender)
 * are 400 or 403 and permanent: the origin dead-letters them. Everything
 * after is the apply service's answer.
 */
final class EventReceiverController implements ContainerInjectionInterface {

  public function __construct(
    private readonly MessageVerifier $verifier,
    private readonly OriginPeer $origin,
    private readonly StreamTracker $stream,
    private readonly SyncApplier $applier,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay.message_verifier'),
      $container->get('content_relay_replica.origin'),
      $container->get('content_relay_replica.stream'),
      $container->get('content_relay_replica.applier'),
      $container->get('logger.channel.content_relay_replica'),
    );
  }

  /**
   * Handles a posted event.
   */
  public function receive(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($data)) {
      return $this->refuse(400, 'malformed', 'The request body is not a JSON object.');
    }
    try {
      $message = $this->verifier->verify($data);
    }
    catch (VerificationException $e) {
      $this->logger->warning('Refused a message from @ip: @reason (@message)', [
        '@ip' => $request->getClientIp(),
        '@reason' => $e->reason,
        '@message' => $e->getMessage(),
      ]);
      return $this->refuse($e->reason === VerificationException::MALFORMED ? 400 : 403, $e->reason, $e->getMessage());
    }
    if ($message->sender !== $this->origin->getSiteId()) {
      return $this->refuse(403, 'unknown_sender', 'This replica does not receive from that site.');
    }
    if ($message->type !== 'event') {
      return $this->refuse(400, 'unsupported_type', sprintf('This endpoint does not accept "%s" messages.', $message->type));
    }
    try {
      $event = SyncEvent::fromArray($message->body);
    }
    catch (InvalidEventException $e) {
      return $this->refuse(400, 'invalid_event', $e->getMessage());
    }
    if ($message->sequence !== $event->sequence) {
      return $this->refuse(400, 'invalid_event', 'The message and event sequence numbers differ.');
    }

    if ($this->stream->record($message->sender, $event->sequence, $event->previousSequence)) {
      $this->logger->warning('Event @sequence from @origin follows @previous, but the last event received was earlier. Events were lost.', [
        '@sequence' => $event->sequence,
        '@origin' => $message->sender,
        '@previous' => $event->previousSequence,
      ]);
    }
    $result = $this->applier->apply($event, $message->sender);
    return new JsonResponse($result->toArray($event->eventId), $result->httpStatus);
  }

  /**
   * A refusal response.
   */
  private function refuse(int $status, string $result, string $message): JsonResponse {
    return new JsonResponse(['result' => $result, 'message' => $message], $status);
  }

}
