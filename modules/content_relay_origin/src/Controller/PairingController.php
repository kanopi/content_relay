<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay_origin\PairingException;
use Drupal\content_relay_origin\PairingService;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives pairing requests from replicas.
 */
final class PairingController implements ContainerInjectionInterface {

  public function __construct(
    private readonly PairingService $pairing,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('content_relay_origin.pairing'));
  }

  /**
   * Handles a pairing request.
   */
  public function pair(Request $request): JsonResponse {
    $body = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['result' => 'malformed', 'message' => 'The request body is not a JSON object.'], 400);
    }
    try {
      return new JsonResponse($this->pairing->pair($body, (string) $request->getClientIp()));
    }
    catch (PairingException $e) {
      return new JsonResponse(['result' => $e->reason, 'message' => $e->getMessage()], $e->httpStatus);
    }
  }

}
