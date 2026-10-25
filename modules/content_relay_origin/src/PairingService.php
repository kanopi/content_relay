<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\PairingRequest;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\PeerKeys;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\SiteIdentity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Psr\Log\LoggerInterface;

/**
 * Pairs a replica that presents a valid code.
 *
 * The replica asks; the origin decides. A valid request registers the replica
 * with the keys it offered, proposes contract version 1 and answers with the
 * origin's public keys and that contract, signed. No event is sent until the
 * replica has acknowledged the contract, and, when approval is required, an
 * administrator has approved the replica.
 *
 * Failed attempts count against the requesting IP address in the flood table.
 */
class PairingService {

  public const FLOOD_EVENT = 'content_relay_origin.pairing';

  public function __construct(
    protected PairingCodes $codes,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected KeyStore $keyStore,
    protected SiteIdentity $siteIdentity,
    protected FloodInterface $flood,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Handles a pairing request.
   *
   * @param array $request
   *   The decoded request body.
   * @param string $ip
   *   The requesting IP address, for flood control.
   *
   * @return array
   *   The response body.
   *
   * @throws \Drupal\content_relay_origin\PairingException
   *   When the request is refused.
   */
  public function pair(array $request, string $ip): array {
    $settings = $this->configFactory->get('content_relay_origin.settings');
    $limit = max(1, (int) $settings->get('pairing.flood_limit'));
    $window = max(1, (int) $settings->get('pairing.flood_window'));
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $limit, $window, $ip)) {
      throw new PairingException('flood', 'Too many failed pairing attempts. Try again later.', 429);
    }

    try {
      $body = $this->validate($request);
      $allowed = $this->codes->consume((string) $body['code'], $body['site_id'])['allowed_bundles'];
    }
    catch (PairingException $e) {
      $this->flood->register(self::FLOOD_EVENT, $window, $ip);
      $this->logger->warning('Refused a pairing request from @ip: @reason', ['@ip' => $ip, '@reason' => $e->reason]);
      throw $e;
    }

    $require_approval = (bool) $settings->get('pairing.require_approval');
    $this->registry->save(new Replica(
      $body['site_id'],
      $body['label'],
      $body['webhook_url'],
      Replica::PENDING,
      $body['signing_keys'],
      $body['encryption_keys'],
      0,
      NULL,
      FALSE,
    ));
    $replica_terms = array_intersect_key($body['requested'], SubscriptionContract::defaultReplicaTerms());
    $replica_terms['webhook_url'] = $body['webhook_url'];
    $origin_terms = ['allowed_bundles' => $allowed] + SubscriptionContract::defaultOriginTerms();
    $contract = $this->contracts->propose($body['site_id'], $replica_terms, $origin_terms, 'replica');

    $this->logger->notice('Paired replica @label (@id) from @ip. @approval', [
      '@label' => $body['label'],
      '@id' => $body['site_id'],
      '@ip' => $ip,
      '@approval' => $require_approval ? 'Waiting for approval.' : 'Waiting for its contract acknowledgement.',
    ]);
    return [
      'result' => 'paired',
      'origin' => [
        'site_id' => $this->siteIdentity->getSiteId(),
        'label' => $this->siteIdentity->getLabel(),
        'signing_keys' => $this->keyStore->getPublicKeys(KeyPurpose::Signing),
        'encryption_keys' => $this->keyStore->getPublicKeys(KeyPurpose::Encryption),
      ],
      'approval_required' => $require_approval,
      'contract' => $this->contracts->sign($contract)->toArray(),
    ];
  }

  /**
   * Checks a request's shape, signature, freshness and address.
   *
   * @return array
   *   The request body.
   *
   * @throws \Drupal\content_relay_origin\PairingException
   */
  protected function validate(array $request): array {
    try {
      $body = PairingRequest::verify($request);
    }
    catch (VerificationException $e) {
      throw new PairingException($e->reason, $e->getMessage(), 400);
    }
    if (($body['v'] ?? NULL) !== 1 || !is_string($body['code'] ?? NULL) || !is_string($body['label'] ?? NULL) || !is_array($body['requested'] ?? NULL)) {
      throw new PairingException('malformed', 'The pairing request is incomplete.', 400);
    }
    if (!is_string($body['site_id'] ?? NULL) || !Uuid::isValid($body['site_id'])) {
      throw new PairingException('malformed', 'The replica site ID must be a UUID.', 400);
    }
    if ($body['site_id'] === $this->siteIdentity->getSiteId()) {
      throw new PairingException('malformed', 'A site cannot pair with itself.', 400);
    }
    if (!is_string($body['webhook_url'] ?? NULL) || !str_starts_with(strtolower($body['webhook_url']), 'https://') || !filter_var($body['webhook_url'], \FILTER_VALIDATE_URL)) {
      throw new PairingException('insecure_url', 'The replica endpoint must be an HTTPS URL.', 400);
    }
    if (!is_int($body['timestamp'] ?? NULL) || abs($this->time->getCurrentTime() - $body['timestamp']) > 300) {
      throw new PairingException('stale_timestamp', 'The pairing request timestamp is too far from this site\'s clock.', 400);
    }
    $body['signing_keys'] = $this->checkKeys($body['signing_keys'] ?? NULL, KeyPurpose::Signing);
    $body['encryption_keys'] = $this->checkKeys($body['encryption_keys'] ?? [], KeyPurpose::Encryption);
    $existing = $this->registry->get($body['site_id']);
    if ($existing !== NULL && $existing->status !== Replica::REVOKED) {
      throw new PairingException('already_paired', 'This replica is already paired. Revoke it on the origin to pair again.', 409);
    }
    return $body;
  }

  /**
   * Checks offered public keys: decodable, right size, IDs derived from them.
   *
   * @return array<string, array{public_key: string, status: string}>
   *   The keys.
   */
  protected function checkKeys(mixed $keys, KeyPurpose $purpose): array {
    try {
      $checked = PeerKeys::check($keys, $purpose);
    }
    catch (\InvalidArgumentException $e) {
      throw new PairingException('malformed', $e->getMessage(), 400);
    }
    if ($purpose === KeyPurpose::Signing && $checked === []) {
      throw new PairingException('malformed', 'At least one signing key is required.', 400);
    }
    return $checked;
  }

}
