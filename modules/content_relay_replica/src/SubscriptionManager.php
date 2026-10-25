<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\PairingRequest;
use Drupal\content_relay\Crypto\SignedEnvelope;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\Key\PeerKeys;
use Drupal\content_relay_replica\Crypto\OriginPolicy;
use Drupal\content_relay\SiteIdentity;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * This replica's side of pairing and of its subscription contract.
 *
 * - pair(): presents a pairing code, stores the origin and contract version 1
 *   and acknowledges it.
 * - propose(): asks the origin for new terms. The origin answers with the new
 *   contract, which is stored and acknowledged. A proposal that cannot reach
 *   the origin is kept and retried by cron; until it is confirmed the origin
 *   keeps sending under the previous contract.
 * - receivePushed(): stores a contract the origin sent on its own initiative
 *   and returns the signed acknowledgement for the response.
 *
 * Whenever a stored version removes a bundle, items of that bundle are marked
 * detached by the origin: kept, no longer updated.
 *
 * Proposals carry this site's current public keys, so the origin learns new
 * ones before they are used, and contracts carry the origin's, so this site
 * learns of the origin's rotations.
 */
class SubscriptionManager {

  public const PENDING_PROPOSAL = 'content_relay_replica.pending_proposal';

  /**
   * Marks items detached because their bundle left the contract.
   */
  public const WITHDRAWN = 'Bundle withdrawn from the subscription contract.';

  public function __construct(
    protected OriginPeer $origin,
    protected ContractStore $contracts,
    protected KeyStore $keyStore,
    protected SiteIdentity $siteIdentity,
    protected MessageSigner $signer,
    protected MessageVerifier $verifier,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected OriginPolicy $policy,
    protected LanguageSubscription $languages,
  ) {}

  /**
   * Pairs with an origin.
   *
   * @param string $origin_url
   *   The origin's base URL. Must be HTTPS.
   * @param string $code
   *   The pairing code, as typed.
   * @param array $replica_terms
   *   The terms requested, at least the bundles.
   * @param int|null $uid
   *   The user pairing.
   *
   * @return array{contract: \Drupal\content_relay\Contract\SubscriptionContract, approval_required: bool, acknowledged: bool}
   *   The outcome.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When pairing fails.
   */
  public function pair(string $origin_url, string $code, array $replica_terms, ?int $uid = NULL): array {
    $origin_url = rtrim(trim($origin_url), '/');
    if (!str_starts_with(strtolower($origin_url), 'https://') || !filter_var($origin_url, \FILTER_VALIDATE_URL)) {
      throw new SubscriptionException('Content Relay pairs only over HTTPS. Enter the origin\'s https:// address.');
    }
    if ($this->origin->get() !== NULL) {
      throw new SubscriptionException('This site is already paired with an origin.');
    }
    $webhook = $this->getWebhookUrl();
    if (!str_starts_with(strtolower($webhook), 'https://')) {
      throw new SubscriptionException(sprintf('This site\'s endpoint (%s) is not HTTPS, so the origin would refuse it. Serve the site over HTTPS or set the webhook URL in settings.', $webhook));
    }

    try {
      $signing = $this->keyStore->getPrimary(KeyPurpose::Signing);
    }
    catch (\RuntimeException $e) {
      throw new SubscriptionException($e->getMessage(), 0, $e);
    }
    $request = PairingRequest::sign([
      'v' => 1,
      'code' => $code,
      'timestamp' => $this->time->getCurrentTime(),
      'site_id' => $this->siteIdentity->getSiteId(),
      'label' => $this->siteIdentity->getLabel(),
      'webhook_url' => $webhook,
      'signing_keys' => $this->keyStore->getPublicKeys(KeyPurpose::Signing),
      'encryption_keys' => $this->keyStore->getPublicKeys(KeyPurpose::Encryption),
      'requested' => ['require_encryption' => $this->requiresEncryption()] + $replica_terms + SubscriptionContract::defaultReplicaTerms(),
    ], $signing);

    [$status, $body] = $this->post($origin_url . '/content-relay/v1/pair', $request);
    if ($status !== 200 || ($body['result'] ?? NULL) !== 'paired' || !is_array($body['origin'] ?? NULL) || !is_array($body['contract'] ?? NULL)) {
      throw new SubscriptionException(sprintf('The origin refused to pair: %s', $body['message'] ?? sprintf('HTTP %d', $status)));
    }

    // The origin is trusted from here on because HTTPS authenticated it and
    // it accepted the code; its keys verify everything that follows.
    $this->origin->set([
      'site_id' => (string) $body['origin']['site_id'],
      'label' => (string) ($body['origin']['label'] ?? $body['origin']['site_id']),
      'url' => $origin_url,
      'signing_keys' => (array) ($body['origin']['signing_keys'] ?? []),
      'encryption_keys' => (array) ($body['origin']['encryption_keys'] ?? []),
    ]);
    try {
      $contract = $this->accept($body['contract'], $uid);
    }
    catch (SubscriptionException $e) {
      $this->state->delete(OriginPeer::STATE_KEY);
      throw $e;
    }
    $this->logger->notice('Paired with origin @origin (@url); contract version @version.', [
      '@origin' => $body['origin']['site_id'],
      '@url' => $origin_url,
      '@version' => $contract->version(),
    ]);
    return [
      'contract' => $contract,
      'approval_required' => (bool) ($body['approval_required'] ?? FALSE),
      'acknowledged' => $this->acknowledge($contract->version()),
    ];
  }

  /**
   * Proposes new terms to the origin.
   *
   * @param array $replica_terms
   *   The full replica terms wanted.
   * @param int|null $uid
   *   Who changed them.
   *
   * @return \Drupal\content_relay\Contract\SubscriptionContract|null
   *   The resulting contract, or NULL if the origin could not be reached and
   *   the proposal was kept for a retry.
   */
  public function propose(array $replica_terms, ?int $uid = NULL): ?SubscriptionContract {
    $replica_terms['webhook_url'] = $this->getWebhookUrl();
    $replica_terms['require_encryption'] = $this->requiresEncryption();
    $this->state->set(self::PENDING_PROPOSAL, [
      'terms' => $replica_terms,
      'uid' => $uid,
      'attempts' => 0,
      'created' => $this->time->getCurrentTime(),
    ]);
    $this->logger->notice('Subscription change proposed by user @uid.', ['@uid' => $uid ?? 0]);
    return $this->sendPendingProposal();
  }

  /**
   * Sends the kept proposal, if any. Called by propose() and by cron.
   */
  public function sendPendingProposal(): ?SubscriptionContract {
    $pending = $this->state->get(self::PENDING_PROPOSAL);
    $origin = $this->origin->get();
    if (!is_array($pending) || $origin === NULL) {
      return NULL;
    }
    $pending['attempts']++;
    $this->state->set(self::PENDING_PROPOSAL, $pending);

    $envelope = $this->signer->sign($origin['site_id'], 'subscription_update', [
      'replica_terms' => $pending['terms'],
      'keys' => [
        'signing' => $this->keyStore->getPublicKeys(KeyPurpose::Signing),
        'encryption' => $this->keyStore->getPublicKeys(KeyPurpose::Encryption),
      ],
    ]);
    try {
      [$status, $body] = $this->post($origin['url'] . '/content-relay/v1/messages', $envelope->toArray());
      if ($status !== 200 || !is_array($body['contract'] ?? NULL)) {
        throw new SubscriptionException(sprintf('The origin answered HTTP %d: %s', $status, $body['message'] ?? ''));
      }
      $contract = $this->accept($body['contract'], $pending['uid']);
    }
    catch (SubscriptionException $e) {
      $this->logger->warning('The subscription change could not be confirmed (attempt @attempts); it will be retried: @message', [
        '@attempts' => $pending['attempts'],
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
    $this->state->delete(self::PENDING_PROPOSAL);
    $this->acknowledge($contract->version());
    return $contract;
  }

  /**
   * Whether a proposal is waiting to reach the origin.
   */
  public function hasPendingProposal(): bool {
    return is_array($this->state->get(self::PENDING_PROPOSAL));
  }

  /**
   * Stores a contract the origin pushed, and signs the acknowledgement.
   *
   * @param \Drupal\content_relay\Crypto\Message $message
   *   The verified contract message.
   */
  public function receivePushed(Message $message): SignedEnvelope {
    $contract = $this->store($message);
    return $this->signer->sign($message->sender, 'contract_ack', ['version' => $contract->version()]);
  }

  /**
   * The replica terms in force, or the defaults.
   */
  public function getReplicaTerms(): array {
    return ($this->contracts->getCurrent()?->terms['replica_terms'] ?? []) + SubscriptionContract::defaultReplicaTerms();
  }

  /**
   * Whether this site refuses unencrypted messages, by its own setting.
   */
  protected function requiresEncryption(): bool {
    return (bool) $this->configFactory->get('content_relay.settings')->get('encryption.require');
  }

  /**
   * The URL the origin posts events and contracts to.
   */
  public function getWebhookUrl(): string {
    $configured = (string) $this->configFactory->get('content_relay_replica.settings')->get('webhook_url');
    return $configured !== '' ? $configured : Url::fromRoute('content_relay_replica.receive_events')->setAbsolute()->toString();
  }

  /**
   * Verifies and stores a signed contract from the origin.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  protected function accept(array $envelope, ?int $uid): SubscriptionContract {
    try {
      $message = $this->verifier->verify($envelope);
    }
    catch (VerificationException $e) {
      throw new SubscriptionException(sprintf('The contract from the origin does not verify: %s', $e->reason), 0, $e);
    }
    return $this->store($message, $uid);
  }

  /**
   * Stores a verified contract message.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  protected function store(Message $message, ?int $uid = NULL): SubscriptionContract {
    if ($message->type !== 'contract') {
      throw new SubscriptionException(sprintf('Expected a contract, got "%s".', $message->type));
    }
    try {
      $contract = SubscriptionContract::fromArray($message->body);
    }
    catch (\InvalidArgumentException $e) {
      throw new SubscriptionException('The contract is malformed: ' . $e->getMessage(), 0, $e);
    }
    if ($contract->originId() !== $message->sender || $contract->replicaId() !== $this->siteIdentity->getSiteId()) {
      throw new SubscriptionException('The contract names a different origin or replica.');
    }
    $previous = $this->contracts->getCurrent();
    if ($previous !== NULL && $contract->version() < $previous->version()) {
      // An old version arriving late changes nothing; it is acknowledged so
      // the origin stops sending it.
      return $contract;
    }
    if ($this->contracts->save($contract, $uid)) {
      $this->applyBundleChanges($previous, $contract);
      $this->languages->contractChanged($previous, $contract);
      $this->takeOriginKeys($contract);
      if ($previous !== NULL && $previous->encryption() !== $contract->encryption()) {
        if ($contract->encryption() !== 'none') {
          $this->policy->recordChange();
        }
        $this->logger->notice('Encryption changed from @old to @new with contract version @version (user @uid).', [
          '@old' => $previous->encryption(),
          '@new' => $contract->encryption(),
          '@version' => $contract->version(),
          '@uid' => $uid ?? 0,
        ]);
      }
      $this->logger->notice('Stored contract version @version from the origin.', ['@version' => $contract->version()]);
    }
    return $contract;
  }

  /**
   * Takes the origin's current public keys from a contract it signed.
   */
  protected function takeOriginKeys(SubscriptionContract $contract): void {
    $keys = $contract->terms['joint']['origin_keys'] ?? NULL;
    if (!is_array($keys) || !is_array($keys['signing'] ?? NULL) || array_is_list($keys['signing'])) {
      return;
    }
    try {
      $signing = PeerKeys::check($keys['signing'], KeyPurpose::Signing);
      $encryption = PeerKeys::check($keys['encryption'] ?? [], KeyPurpose::Encryption);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('The origin\'s keys in contract version @version are malformed and were ignored: @message', [
        '@version' => $contract->version(),
        '@message' => $e->getMessage(),
      ]);
      return;
    }
    if ($signing !== []) {
      $this->origin->setKeys($signing, $encryption);
    }
  }

  /**
   * Detaches items of withdrawn bundles and reattaches ones granted again.
   */
  protected function applyBundleChanges(?SubscriptionContract $previous, SubscriptionContract $contract): void {
    foreach (($previous?->effectiveBundles() ?? []) as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        if (!$contract->allowsBundle($entity_type_id, $bundle)) {
          $this->database->update(SyncStateStore::TABLE)
            ->fields(['status' => SyncStatus::DETACHED_BY_ORIGIN, 'last_error' => self::WITHDRAWN])
            ->condition('entity_type', $entity_type_id)
            ->condition('bundle', $bundle)
            ->condition('status', [SyncStatus::DETACHED_BY_ORIGIN, SyncStatus::DETACHED_BY_REPLICA], 'NOT IN')
            ->execute();
        }
      }
    }
    foreach ($contract->effectiveBundles() as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        // Only what a withdrawal detached; an item the origin detached on its
        // own is reattached by the origin, not by a contract.
        $this->database->update(SyncStateStore::TABLE)
          ->fields(['status' => SyncStatus::SYNCED, 'last_error' => NULL])
          ->condition('entity_type', $entity_type_id)
          ->condition('bundle', $bundle)
          ->condition('status', SyncStatus::DETACHED_BY_ORIGIN)
          ->condition('last_error', self::WITHDRAWN)
          ->execute();
      }
    }
  }

  /**
   * Sends a signed acknowledgement of a version to the origin.
   */
  protected function acknowledge(int $version): bool {
    $origin = $this->origin->get();
    if ($origin === NULL) {
      return FALSE;
    }
    $envelope = $this->signer->sign($origin['site_id'], 'contract_ack', ['version' => $version]);
    try {
      [$status] = $this->post($origin['url'] . '/content-relay/v1/messages', $envelope->toArray());
    }
    catch (SubscriptionException $e) {
      // The origin keeps sending the contract until it hears back, and each
      // push is acknowledged in its response, so a lost acknowledgement heals.
      $this->logger->warning('Acknowledging contract version @version failed: @message', [
        '@version' => $version,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    return $status === 200;
  }

  /**
   * Posts JSON and decodes the answer.
   *
   * @return array{0: int, 1: array}
   *   Status code and decoded body.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the request cannot be made.
   */
  protected function post(string $url, array $body): array {
    try {
      $response = $this->httpClient->request('POST', $url, [
        'json' => $body,
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new SubscriptionException(sprintf('The origin could not be reached: %s', $e->getMessage()), 0, $e);
    }
    $decoded = json_decode((string) $response->getBody(), TRUE);
    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
  }

}
