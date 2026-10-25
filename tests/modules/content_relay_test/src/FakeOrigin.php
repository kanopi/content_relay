<?php

declare(strict_types=1);

namespace Drupal\content_relay_test;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\FileCipher;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Schema\SchemaDescriber;
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
 * - schema: what the schema endpoint answers, by entity type and bundle;
 *   without it, this site's own allowed bundles are described, so content
 *   fits as it is.
 * - items: what the item endpoint answers, event arrays by
 *   "entity_type:uuid".
 * - hashes: what the hash listing serves, by "entity_type:bundle": lists of
 *   entries (uuid, revision, changed, hash, not_syncable), paged by UUID.
 *   hashes_contract_version is the contract version it reports (1).
 * - stream: what the stream endpoint knows, entries by sequence.
 * - rate_limited: the number of read requests (items, hashes, stream) to
 *   answer 429, counting down; rate_limited_items the same for items only.
 * - files: files it serves, by UUID: content, uri and, to serve it
 *   encrypted, key (base64 file key). Downloads are
 *   answered 410 while expire_downloads is positive and with wrong bytes
 *   while corrupt_downloads is, each counting down; downloads counts them.
 */
final class FakeOrigin {

  public const STATE = 'content_relay_test.fake_origin';
  public const HOST = 'origin.example.com';
  public const SITE_ID = '0b0e3c7a-55a1-4f0a-9d0c-6f1f6c2d9a10';

  public function __construct(
    private readonly StateInterface $state,
    private readonly SchemaDescriber $describer,
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
      $path = $request->getUri()->getPath();
      $limited = match (TRUE) {
        ($config['rate_limited'] ?? 0) > 0 && preg_match('#^/content-relay/v1/(items/|hashes|stream)#', $path) === 1 => 'rate_limited',
        ($config['rate_limited_items'] ?? 0) > 0 && str_starts_with($path, '/content-relay/v1/items/') => 'rate_limited_items',
        default => NULL,
      };
      if ($limited !== NULL) {
        $config[$limited]--;
        $this->state->set(self::STATE, $config);
        return Create::promiseFor(new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '30'], json_encode(['result' => 'rate_limited'])));
      }
      if (preg_match('#^/content-relay/v1/files/([0-9a-f\-]{36})(/download)?$#', $path, $m)) {
        [$status, $answer] = empty($m[2]) ? $this->fileInfo($config, $m[1], $request) : $this->download($config, $m[1]);
        $this->state->set(self::STATE, $config);
        if (is_string($answer)) {
          // A file: written where the client asked, as a real download is.
          if (isset($options['sink']) && is_string($options['sink'])) {
            file_put_contents($options['sink'], $answer);
          }
          return Create::promiseFor(new Response($status, ['Content-Type' => 'application/octet-stream'], $answer));
        }
        if (isset($options['sink']) && is_string($options['sink'])) {
          file_put_contents($options['sink'], json_encode($answer));
        }
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($answer)));
      }
      if (preg_match('#^/content-relay/v1/items/([a-z0-9_]+)/([0-9a-f\-]{36})$#', $path, $m)) {
        $event = $config['items'][$m[1] . ':' . $m[2]] ?? NULL;
        [$status, $answer] = $event === NULL ? [404, ['result' => 'not_found']] : [
          200,
          $this->sign($config, 'item', ['event' => $event]),
        ];
        $this->state->set(self::STATE, $config);
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($answer)));
      }
      parse_str($request->getUri()->getQuery(), $query);
      [$status, $answer] = match ($path) {
        '/content-relay/v1/hashes' => $this->hashes($config, $query),
        '/content-relay/v1/stream' => [
          200,
          $this->sign($config, 'stream', [
            'contract_version' => 1,
            'events' => array_values(array_filter(array_map(fn ($sequence) => $config['stream'][(int) $sequence] ?? NULL, explode(',', (string) ($query['sequences'] ?? ''))))),
          ]),
        ],
        '/content-relay/v1/schema' => [
          200,
          $this->sign($config, 'schema', ['bundles' => $config['schema'] ?? $this->describer->describeMany($config['allowed'])]),
        ],
        '/content-relay/v1/pair' => $this->pair($config, $body),
        '/content-relay/v1/messages' => $this->message($config, $body),
        default => [404, ['result' => 'not_found']],
      };
      $this->state->set(self::STATE, $config);
      return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($answer)));
    };
  }

  /**
   * Answers a page of the hash listing.
   */
  private function hashes(array $config, array $query): array {
    $key = ($query['entity_type'] ?? '') . ':' . ($query['bundle'] ?? '');
    if (!isset($config['hashes'][$key])) {
      return [404, ['result' => 'not_found']];
    }
    $entries = $config['hashes'][$key];
    usort($entries, fn ($a, $b) => strcmp($a['uuid'], $b['uuid']));
    $after = (string) ($query['after'] ?? '');
    $entries = array_values(array_filter($entries, fn ($entry) => strcmp($entry['uuid'], $after) > 0));
    $limit = max(1, (int) ($query['limit'] ?? 200));
    $page = array_slice($entries, 0, $limit);
    return [200, $this->sign($config, 'hashes', [
      'entity_type' => $query['entity_type'],
      'bundle' => $query['bundle'],
      'contract_version' => $config['hashes_contract_version'] ?? 1,
      'items' => $page,
      'next' => count($page) === $limit ? end($page)['uuid'] : NULL,
    ]),
    ];
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
   * Metadata for a file, as the origin puts it in events and file answers.
   */
  public static function fileMeta(string $uuid, string $content, string $uri, int $expires = 0, ?string $key = NULL): array {
    return [
      'encryption' => $key === NULL ? 'none' : ['cipher' => FileCipher::NAME, 'key' => $key],
      'uuid' => $uuid,
      'uri' => $uri,
      'sha256' => hash('sha256', $content),
      'size' => strlen($content),
      'download' => '/content-relay/v1/files/' . $uuid . '/download?token=' . bin2hex(random_bytes(4)),
      'expires' => $expires ?: time() + 3600,
      'url' => '/sites/default/files/' . explode('://', $uri, 2)[1],
    ];
  }

  /**
   * Answers the file endpoint.
   */
  private function fileInfo(array &$config, string $uuid, RequestInterface $request): array {
    if (!str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')) {
      return [401, ['result' => 'unauthenticated']];
    }
    $file = $config['files'][$uuid] ?? NULL;
    if ($file === NULL) {
      return [404, ['result' => 'not_found']];
    }
    return [
      200,
      $this->sign($config, 'file_info', self::fileMeta($uuid, $file['content'], $file['uri'], 0, $file['key'] ?? NULL)),
    ];
  }

  /**
   * Answers a download.
   *
   * @return array{0: int, 1: array|string}
   *   Status, and the bytes or an error answer.
   */
  private function download(array &$config, string $uuid): array {
    $config['downloads'] = ($config['downloads'] ?? 0) + 1;
    $file = $config['files'][$uuid] ?? NULL;
    if ($file === NULL) {
      return [404, ['result' => 'not_found']];
    }
    if (($config['expire_downloads'] ?? 0) > 0) {
      $config['expire_downloads']--;
      return [410, ['result' => 'expired']];
    }
    if (($config['corrupt_downloads'] ?? 0) > 0) {
      $config['corrupt_downloads']--;
      return [200, 'corrupted ' . $file['content']];
    }
    if (isset($file['key'])) {
      $in = fopen('php://memory', 'w+');
      fwrite($in, $file['content']);
      rewind($in);
      $out = fopen('php://memory', 'w+');
      FileCipher::encrypt($in, $out, base64_decode($file['key']));
      rewind($out);
      return [200, (string) stream_get_contents($out)];
    }
    return [200, $file['content']];
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
    $message = new Message((new PhpUuid())->generate(), $type, self::SITE_ID, $config['replica'] ?? 'not-paired-yet', time(), EncryptionMode::None, $body);
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
