<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\PairingRequest;
use Drupal\content_relay\Crypto\SignedEnvelope;
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
   * The replica's encryption key.
   */
  public KeyPair $encryption;

  /**
   * The origin's encryption key to seal messages to, once known.
   *
   * @var array{id: string, public_key: string}|null
   */
  public ?array $originEncryptionKey = NULL;

  /**
   * Seal messages to the origin.
   */
  public bool $seal = FALSE;

  /**
   * Envelopes received, as sent.
   *
   * @var array[]
   */
  public array $envelopes = [];

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
   * Contracts received, in full.
   *
   * @var array[]
   */
  public array $contractTerms = [];

  /**
   * Responses to give to events, in order: [status, body]. Empty for 200.
   *
   * @var array<array{0: int, 1: array}>
   */
  public array $eventResponses = [];

  /**
   * Answers to status requests, by event ID. Unlisted events are unknown.
   *
   * @var array<string, array>
   */
  public array $statuses = [];

  /**
   * The contract version status answers report; the last received if NULL.
   */
  public ?int $reportedContractVersion = NULL;

  /**
   * Event IDs asked about, per status request.
   *
   * @var array<string[]>
   */
  public array $statusRequests = [];

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
    [$this->encryption] = KeyPair::generate(KeyPurpose::Encryption);
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
      'encryption_keys' => [
        $this->encryption->id() => ['public_key' => base64_encode($this->encryption->publicKey), 'status' => 'primary'],
      ],
      'requested' => ['bundles' => $bundles] + SubscriptionContract::defaultReplicaTerms(),
    ], $this->key);
  }

  /**
   * A signed message from this replica to the origin, sealed when set to.
   */
  public function message(string $type, array $body, ?KeyPair $key = NULL): array {
    $key ??= $this->key;
    $mode = $this->seal ? EncryptionMode::Sealed : EncryptionMode::None;
    $message = new Message((new PhpUuid())->generate(), $type, $this->siteId, $this->originId, time(), $mode, $body);
    $envelope = new SignedEnvelope($this->siteId, $key->id(), $mode, $message->toArray(), base64_encode($key->sign(MessageSigner::signedBytes($key->id(), $message->toArray()))));
    if ($this->seal) {
      return MessageSigner::seal($envelope, $this->originEncryptionKey['id'], $this->originEncryptionKey['public_key'])->toArray();
    }
    return [
      'v' => 1,
      'sender' => $this->siteId,
      'signing_key_id' => $key->id(),
      'encryption' => 'none',
      'message' => $envelope->message,
      'signature' => $envelope->signature,
    ];
  }

  /**
   * This replica's public keys, as it announces them.
   *
   * @param \Drupal\content_relay\Key\KeyPair[] $signing
   *   Signing keys, the first primary.
   * @param \Drupal\content_relay\Key\KeyPair|null $encryption
   *   The encryption key, if any.
   */
  public static function keySet(array $signing, ?KeyPair $encryption = NULL): array {
    $keys = ['signing' => [], 'encryption' => []];
    foreach (array_values($signing) as $i => $key) {
      $keys['signing'][$key->id()] = [
        'public_key' => base64_encode($key->publicKey),
        'status' => $i === 0 ? 'primary' : 'active',
      ];
    }
    if ($encryption !== NULL) {
      $keys['encryption'][$encryption->id()] = [
        'public_key' => base64_encode($encryption->publicKey),
        'status' => 'primary',
      ];
    }
    return $keys;
  }

  /**
   * Opens an envelope as received, sealed or not.
   */
  public function open(array $envelope): array {
    if (($envelope['encryption'] ?? 'none') === 'none') {
      return $envelope['message'];
    }
    $inner = json_decode((string) $this->encryption->open(base64_decode($envelope['ciphertext'])), TRUE);
    return $inner['message'];
  }

  /**
   * The Guzzle handler.
   */
  public function __invoke(RequestInterface $request, array $options) {
    if ($this->down) {
      return Create::rejectionFor(new ConnectException('Replica is down', $request));
    }
    $envelope = json_decode((string) $request->getBody(), TRUE);
    $this->envelopes[] = $envelope;
    $message = $this->open($envelope);
    if ($message['type'] === 'contract') {
      $this->contracts[] = $message['body']['version'];
      $this->contractTerms[] = $message['body'];
      $body = ['result' => 'acknowledged'];
      if (!$this->dropAcks) {
        $body['ack'] = $this->message('contract_ack', ['version' => $message['body']['version']]);
      }
      return Create::promiseFor(new Response(200, [], json_encode($body)));
    }
    if ($message['type'] === 'status_request') {
      $ids = $message['body']['event_ids'];
      $this->statusRequests[] = $ids;
      $events = [];
      foreach ($ids as $id) {
        $events[$id] = $this->statuses[$id] ?? ['state' => 'unknown'];
      }
      $report = [
        'events' => $events,
        'contract_version' => $this->reportedContractVersion ?? (end($this->contracts) ?: NULL),
        'last_sequence' => 0,
      ];
      return Create::promiseFor(new Response(200, [], json_encode([
        'result' => 'status',
        'status' => $this->message('status', $report),
      ])));
    }
    $this->events[] = $message['body'];
    [$status, $body] = array_shift($this->eventResponses) ?? [200, ['result' => 'applied']];
    return Create::promiseFor(new Response($status, [], json_encode($body)));
  }

}
