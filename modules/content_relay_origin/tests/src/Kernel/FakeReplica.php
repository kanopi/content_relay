<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\PairingRequest;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A replica on the other end of the origin's HTTP client.
 *
 * It answers contract pushes with a signed acknowledgement and events with
 * whatever the test queued (200 by default), and records what it received.
 */
final class FakeReplica {

  public const ENDPOINT = 'https://replica.example.com/content-relay/v1/events';

  /**
   * The replica's signing key.
   */
  public KeyPair $key;

  /**
   * The replica's site ID.
   */
  public string $siteId;

  /**
   * Event bodies received, in order.
   *
   * @var array[]
   */
  public array $events = [];

  /**
   * Contract versions received.
   *
   * @var int[]
   */
  public array $contracts = [];

  /**
   * Responses to give to events, in order: [status, body]. Empty for 200.
   *
   * @var array<array{0: int, 1: array}>
   */
  public array $eventResponses = [];

  /**
   * Fail every request with a connection error.
   */
  public bool $down = FALSE;

  /**
   * Answer contract pushes without an acknowledgement.
   */
  public bool $dropAcks = FALSE;

  public function __construct(
    public string $originId,
  ) {
    [$this->key] = KeyPair::generate(KeyPurpose::Signing);
    $this->siteId = (new PhpUuid())->generate();
  }

  /**
   * A signed pairing request.
   */
  public function pairingRequest(string $code, array $bundles = ['node' => ['article']], array $overrides = []): array {
    return PairingRequest::sign($overrides + [
      'v' => 1,
      'code' => $code,
      'timestamp' => time(),
      'site_id' => $this->siteId,
      'label' => 'Fake replica',
      'webhook_url' => self::ENDPOINT,
      'signing_keys' => [
        $this->key->id() => ['public_key' => base64_encode($this->key->publicKey), 'status' => 'primary'],
      ],
      'encryption_keys' => [],
      'requested' => ['bundles' => $bundles] + SubscriptionContract::defaultReplicaTerms(),
    ], $this->key);
  }

  /**
   * A signed message from this replica to the origin.
   */
  public function message(string $type, array $body): array {
    $message = new Message((new PhpUuid())->generate(), $type, $this->siteId, $this->originId, time(), EncryptionMode::None, $body);
    return [
      'v' => 1,
      'sender' => $this->siteId,
      'signing_key_id' => $this->key->id(),
      'encryption' => 'none',
      'message' => $message->toArray(),
      'signature' => base64_encode($this->key->sign(MessageSigner::signedBytes($this->key->id(), $message->toArray()))),
    ];
  }

  /**
   * The Guzzle handler.
   */
  public function __invoke(RequestInterface $request, array $options) {
    if ($this->down) {
      return Create::rejectionFor(new ConnectException('Replica is down', $request));
    }
    $envelope = json_decode((string) $request->getBody(), TRUE);
    $message = $envelope['message'];
    if ($message['type'] === 'contract') {
      $this->contracts[] = $message['body']['version'];
      $body = ['result' => 'acknowledged'];
      if (!$this->dropAcks) {
        $body['ack'] = $this->message('contract_ack', ['version' => $message['body']['version']]);
      }
      return Create::promiseFor(new Response(200, [], json_encode($body)));
    }
    $this->events[] = $message['body'];
    [$status, $body] = array_shift($this->eventResponses) ?? [200, ['result' => 'applied']];
    return Create::promiseFor(new Response($status, [], json_encode($body)));
  }

}
