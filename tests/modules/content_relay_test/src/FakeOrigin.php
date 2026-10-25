<?php

declare(strict_types=1);

namespace Drupal\content_relay_test;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * An origin inside the test site, reached through the HTTP client.
 *
 * Requests to https://origin.example.com are answered here instead of going
 * out. It pairs with a code, answers subscription updates with signed
 * contracts and records acknowledgements, much like the real origin, so
 * replica code runs unchanged in kernel and functional tests.
 *
 * Everything it knows is in state under STATE, so a test and the site under
 * test (a separate process in functional tests) share it:
 *
 * - key: the origin's signing key material.
 * - code: the one pairing code it accepts.
 * - allowed: bundles it grants, by entity type.
 * - down: TRUE to fail every request with a connection error.
 * - versions: contracts issued; acks: versions acknowledged; requests: log.
 * - notices: detach and attach notices received, each type and body.
 */
final class FakeOrigin {

  public const STATE = 'content_relay_test.fake_origin';
  public const HOST = 'origin.example.com';
  public const SITE_ID = '0b0e3c7a-55a1-4f0a-9d0c-6f1f6c2d9a10';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Sets the fake origin up.
   *
   * @return \Drupal\content_relay\Key\KeyPair
   *   Its signing key.
   */
  public static function install(StateInterface $state, string $code, array $allowed): KeyPair {
    [$key, $material] = KeyPair::generate(KeyPurpose::Signing);
    $state->set(self::STATE, [
      'key' => $material,
      'code' => $code,
      'allowed' => $allowed,
      'down' => FALSE,
      'versions' => [],
      'acks' => [],
      'requests' => [],
    ]);
    return $key;
  }

  /**
   * Returns the Guzzle middleware, as http_client_middleware services do.
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
      if (!empty($config['down'])) {
        return Create::rejectionFor(new ConnectException('Fake origin is down', $request));
      }
      $body = json_decode((string) $request->getBody(), TRUE) ?? [];
      $config['requests'][] = ['path' => $request->getUri()->getPath(), 'body' => $body];
      [$status, $answer] = match ($request->getUri()->getPath()) {
        '/content-relay/v1/pair' => $this->pair($config, $body),
        '/content-relay/v1/messages' => $this->message($config, $body),
        default => [404, ['result' => 'not_found']],
      };
      $this->state->set(self::STATE, $config);
      return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($answer)));
    };
  }

  /**
   * Answers a pairing request.
   */
  private function pair(array &$config, array $body): array {
    $normalize = fn (string $code) => strtoupper(str_replace(['-', ' '], '', $code));
    if ($normalize((string) ($body['code'] ?? '')) !== $normalize($config['code'])) {
      return [403, ['result' => 'code_invalid', 'message' => 'The pairing code is not valid.']];
    }
    $config['replica'] = $body['site_id'];
    $contract = $this->contract($config, $body['requested'] ?? []);
    $key = KeyPair::fromMaterial(KeyPurpose::Signing, $config['key']);
    return [200, [
      'result' => 'paired',
      'origin' => [
        'site_id' => self::SITE_ID,
        'label' => 'Fake origin',
        'signing_keys' => [$key->id() => ['public_key' => base64_encode($key->publicKey), 'status' => 'primary']],
        'encryption_keys' => [],
      ],
      'approval_required' => FALSE,
      'contract' => $this->sign($config, 'contract', $contract->toArray()),
    ],
    ];
  }

  /**
   * Answers a signed message from the replica.
   */
  private function message(array &$config, array $envelope): array {
    $message = $envelope['message'] ?? [];
    return match ($message['type'] ?? NULL) {
      'contract_ack' => $this->ack($config, (int) ($message['body']['version'] ?? 0)),
      'subscription_update' => [200, [
        'result' => 'contract',
        'contract' => $this->sign($config, 'contract', $this->contract($config, $message['body']['replica_terms'] ?? [])->toArray()),
      ],
      ],
      'detach_notice', 'attach_notice' => $this->notice($config, $message['type'], $message['body'] ?? []),
      default => [400, ['result' => 'unsupported_type']],
    };
  }

  /**
   * Records a detach or attach notice.
   */
  private function notice(array &$config, string $type, array $body): array {
    $config['notices'][] = ['type' => $type, 'body' => $body];
    return [200, ['result' => 'recorded']];
  }

  /**
   * Records an acknowledgement.
   */
  private function ack(array &$config, int $version): array {
    $config['acks'][] = $version;
    return [200, ['result' => 'acknowledged']];
  }

  /**
   * Issues the next contract version for requested terms.
   */
  private function contract(array &$config, array $requested): SubscriptionContract {
    $requested += SubscriptionContract::defaultReplicaTerms();
    $bundles = [];
    $refused = [];
    foreach ($requested['bundles'] as $entity_type_id => $list) {
      foreach ($list as $bundle) {
        if (in_array($bundle, $config['allowed'][$entity_type_id] ?? [], TRUE)) {
          $bundles[$entity_type_id][] = $bundle;
        }
        else {
          $refused[] = [
            'term' => 'bundle',
            'value' => "$entity_type_id.$bundle",
            'reason' => 'Not allowed for this replica.',
          ];
        }
      }
    }
    $version = count($config['versions']) + 1;
    $config['versions'][] = $version;
    return new SubscriptionContract([
      'version' => $version,
      'origin' => self::SITE_ID,
      'replica' => $config['replica'],
      'effective_from_sequence' => 1,
      'issued' => time(),
      'issued_by' => 'replica',
      'origin_terms' => SubscriptionContract::defaultOriginTerms(),
      'replica_terms' => $requested,
      'joint' => ['event_schema' => 1, 'hash_algorithm' => 1, 'encryption' => 'none'],
      'effective' => [
        'bundles' => $bundles,
        'languages' => $requested['languages'],
        'processing' => $requested['processing'],
        'max_events_per_minute' => $requested['delivery']['max_events_per_minute'] ?? NULL,
        'windows' => [],
        'paused' => (bool) ($requested['delivery']['paused'] ?? FALSE),
        'encryption' => 'none',
      ],
      'not_granted' => $refused,
    ]);
  }

  /**
   * Signs a message from the fake origin to the replica.
   */
  private function sign(array $config, string $type, array $body): array {
    $key = KeyPair::fromMaterial(KeyPurpose::Signing, $config['key']);
    $message = new Message((new PhpUuid())->generate(), $type, self::SITE_ID, $config['replica'], time(), EncryptionMode::None, $body);
    $signature = $key->sign(MessageSigner::signedBytes($key->id(), $message->toArray()));
    return [
      'v' => 1,
      'sender' => self::SITE_ID,
      'signing_key_id' => $key->id(),
      'encryption' => 'none',
      'message' => $message->toArray(),
      'signature' => base64_encode($signature),
    ];
  }

}
