<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Controller;

use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Exception\InvalidEventException;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\ProcessedEvents;
use Drupal\content_relay_replica\Processing\ProcessingPolicy;
use Drupal\content_relay_replica\Processing\ReceivedEvents;
use Drupal\content_relay_replica\Processing\StatusReport;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\content_relay_replica\StreamTracker;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SubscriptionManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives events, contracts and status requests from the origin.
 *
 * Events are applied in the request (immediate, answered with the apply
 * service's answer) or kept and applied by a queue worker (queued, answered
 * 202), as ProcessingPolicy decides. The origin learns how a queued event
 * ended by sending a status_request; the answer is a signed "status" message.
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
    private readonly SubscriptionManager $subscription,
    private readonly LoggerInterface $logger,
    private readonly ProcessingPolicy $policy,
    private readonly ReceivedEvents $received,
    private readonly StatusReport $statusReport,
    private readonly MessageSigner $signer,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProcessedEvents $processed,
    private readonly LanguageSubscription $languages,
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
      $container->get('content_relay_replica.subscription'),
      $container->get('logger.channel.content_relay_replica'),
      $container->get('content_relay_replica.processing_policy'),
      $container->get('content_relay_replica.received_events'),
      $container->get('content_relay_replica.status_report'),
      $container->get('content_relay.message_signer'),
      $container->get('config.factory'),
      $container->get('content_relay_replica.processed_events'),
      $container->get('content_relay_replica.language_subscription'),
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
    if ($message->type === 'contract') {
      try {
        return new JsonResponse([
          'result' => 'acknowledged',
          'ack' => $this->subscription->receivePushed($message)->toArray(),
        ]);
      }
      catch (SubscriptionException $e) {
        return $this->refuse(400, 'invalid_contract', $e->getMessage());
      }
    }
    if ($message->type === 'language_notice') {
      $this->languages->receiveNotice(is_array($message->body['changes'] ?? NULL) ? $message->body['changes'] : []);
      return new JsonResponse(['result' => 'acknowledged']);
    }
    if ($message->type === 'status_request') {
      return $this->status($message->sender, $message->body['event_ids'] ?? NULL);
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
    if ($this->received->has($event->eventId)) {
      return $this->queuedAnswer($event);
    }
    // An event already handled gets its answer again from the apply service.
    $mode = $this->policy->decide($event);
    if ($mode === ProcessingMode::Queued && $this->processedAnswerMissing($event)) {
      $this->received->add($event, $message->sender);
      return $this->queuedAnswer($event);
    }
    $result = $this->applier->apply($event, $message->sender);
    return new JsonResponse($result->toArray($event->eventId), $result->httpStatus);
  }

  /**
   * Whether no answer has been recorded for an event yet.
   */
  private function processedAnswerMissing(SyncEvent $event): bool {
    return $this->processed->get($event->eventId) === NULL;
  }

  /**
   * The answer for an event kept for the queue.
   */
  private function queuedAnswer(SyncEvent $event): JsonResponse {
    return new JsonResponse(['result' => 'accepted', 'event_id' => $event->eventId, 'code' => 'queued'], 202);
  }

  /**
   * Answers a status request with a signed status message.
   */
  private function status(string $origin, mixed $event_ids): JsonResponse {
    $limit = max(1, (int) ($this->configFactory->get('content_relay_replica.settings')->get('processing.status_batch_limit') ?: 200));
    if (!is_array($event_ids) || array_filter($event_ids, fn ($id) => !is_string($id)) !== []) {
      return $this->refuse(400, 'malformed', 'A status request needs a list of event IDs.');
    }
    if (count($event_ids) > $limit) {
      return $this->refuse(400, 'too_many', sprintf('Ask about at most %d events at once.', $limit));
    }
    $report = $this->statusReport->report(array_values(array_unique($event_ids)), $origin);
    return new JsonResponse([
      'result' => 'status',
      'status' => $this->signer->sign($origin, 'status', $report)->toArray(),
    ]);
  }

  /**
   * A refusal response.
   */
  private function refuse(int $status, string $result, string $message): JsonResponse {
    return new JsonResponse(['result' => $result, 'message' => $message], $status);
  }

}
