<?php

declare(strict_types=1);

namespace Drupal\content_relay_test;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A replica endpoint inside the test site, reached through the HTTP client.
 *
 * Requests to https://replica.example.com are answered here while the state
 * value STATE is set: events with the next queued response (200 "applied" by
 * default), anything else with 200. Event bodies are recorded in STATE, so a
 * functional test sees what the origin site under test sent.
 *
 * - responses: list of [status, body] to give to events, in order.
 * - events: event bodies received.
 */
final class FakeReplicaEndpoint {

  public const STATE = 'content_relay_test.fake_replica';
  public const HOST = 'replica.example.com';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns the Guzzle middleware.
   */
  public function __invoke(): callable {
    return fn (callable $handler): callable => $this->handler($handler);
  }

  /**
   * Wraps the next handler.
   */
  private function handler(callable $handler): callable {
    return function (RequestInterface $request, array $options) use ($handler) {
      $config = $this->state->get(self::STATE);
      if (!is_array($config) || $request->getUri()->getHost() !== self::HOST) {
        return $handler($request, $options);
      }
      $envelope = json_decode((string) $request->getBody(), TRUE) ?? [];
      if (($envelope['message']['type'] ?? NULL) !== 'event') {
        return Create::promiseFor(new Response(200, [], json_encode(['result' => 'ok'])));
      }
      $config['events'][] = $envelope['message']['body'];
      [$status, $body] = array_shift($config['responses']) ?? [200, ['result' => 'applied']];
      $this->state->set(self::STATE, $config);
      return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    };
  }

}
