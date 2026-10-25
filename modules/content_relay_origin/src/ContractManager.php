<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\SignedEnvelope;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\SiteIdentity;
use Drupal\content_relay_origin\Crypto\ReplicaPolicy;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Versions, sends and activates replicas' subscription contracts.
 *
 * Every change, from either side, becomes a new version computed here:
 *
 * 1. propose() computes the effective terms, takes the next version number
 *    and records it as pending.
 * 2. The full contract, signed, reaches the replica: in the answer to the
 *    replica's own proposal, or pushed by push().
 * 3. The replica acknowledges it with a signed message, and acknowledge()
 *    makes it active. Until then the previous version stays in force.
 *
 * A version applies from the first event that had not been sent when it was
 * acknowledged. That is recorded alongside the version (history's
 * effective_from); the signed terms keep the sequence known when it was
 * proposed, because they cannot change after the replica has them. Events go
 * out under the version that was active when they were sent, and say which;
 * the replica judges each event by the version it names, not by sequence.
 */
class ContractManager {

  public const TABLE = 'content_relay_contract';

  public const PENDING = 'pending';
  public const ACTIVE = 'active';
  public const SUPERSEDED = 'superseded';

  /**
   * Seconds to wait before pushing an unacknowledged contract again.
   */
  public const PUSH_INTERVAL = 60;

  public function __construct(
    protected Connection $database,
    protected ReplicaRegistry $registry,
    protected Outbox $outbox,
    protected ContractNegotiator $negotiator,
    protected MessageSigner $signer,
    protected MessageVerifier $verifier,
    protected SiteIdentity $siteIdentity,
    protected KeyStore $keyStore,
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected ReplicaPolicy $policy,
  ) {}

  /**
   * Creates the next version of a replica's contract.
   *
   * @param string $replica_id
   *   The replica.
   * @param array|null $replica_terms
   *   New replica terms, or NULL to keep the latest.
   * @param array|null $origin_terms
   *   New origin terms, or NULL to keep the latest.
   * @param string $initiated_by
   *   Which side made the change: "origin" or "replica".
   * @param int|null $uid
   *   The user who made the change, when it was made here.
   */
  public function propose(string $replica_id, ?array $replica_terms, ?array $origin_terms, string $initiated_by, ?int $uid = NULL): SubscriptionContract {
    $replica = $this->registry->get($replica_id) ?? throw new \InvalidArgumentException(sprintf('Unknown replica "%s".', $replica_id));
    $latest = $this->getLatest($replica_id);
    $replica_terms = ($replica_terms ?? $latest?->terms['replica_terms'] ?? []) + SubscriptionContract::defaultReplicaTerms();
    $origin_terms = ($origin_terms ?? $latest?->terms['origin_terms'] ?? []) + SubscriptionContract::defaultOriginTerms();
    // This site's own policy, whatever was proposed.
    $origin_terms['require_encryption'] = (bool) $this->configFactory->get('content_relay.settings')->get('encryption.require');
    $origin_encryption_keys = $this->keyStore->getPublicKeys(KeyPurpose::Encryption);
    $negotiated = $this->negotiator->negotiate($origin_terms, $replica_terms, $origin_encryption_keys !== [] && $replica->encryptionKeys !== []);
    $version = ($latest?->version() ?? 0) + 1;
    $effective_from = $this->registry->peekSequence($replica_id);

    $contract = new SubscriptionContract([
      'version' => $version,
      'origin' => $this->siteIdentity->getSiteId(),
      'replica' => $replica_id,
      'effective_from_sequence' => $effective_from,
      'issued' => $this->time->getCurrentTime(),
      'issued_by' => $initiated_by,
      'origin_terms' => $origin_terms,
      'replica_terms' => $replica_terms,
      'joint' => [
        'event_schema' => SyncEvent::SCHEMA_VERSION,
        'hash_algorithm' => RevisionHasher::CURRENT_VERSION,
        'encryption' => $negotiated['effective']['encryption'],
        'require_encryption' => [
          'origin' => $origin_terms['require_encryption'],
          'replica' => (bool) $replica_terms['require_encryption'],
        ],
        // Each side's public keys and their status. The replica takes the
        // origin's keys from here, so a rotation reaches it with the contract.
        'origin_keys' => [
          'signing' => $this->keyStore->getPublicKeys(KeyPurpose::Signing),
          'encryption' => $origin_encryption_keys,
        ],
        'replica_keys' => [
          'signing' => $replica->signingKeys,
          'encryption' => $replica->encryptionKeys,
        ],
      ],
      'effective' => $negotiated['effective'],
      'not_granted' => $negotiated['not_granted'],
    ]);

    $transaction = $this->database->startTransaction();
    $this->database->update(self::TABLE)
      ->fields(['status' => self::SUPERSEDED])
      ->condition('replica_id', $replica_id)
      ->condition('status', self::PENDING)
      ->execute();
    $this->database->insert(self::TABLE)->fields([
      'replica_id' => $replica_id,
      'version' => $version,
      'terms' => json_encode($contract->toArray(), \JSON_THROW_ON_ERROR),
      'status' => self::PENDING,
      'effective_from_sequence' => $effective_from,
      'created_by' => $uid,
      'initiated_by' => $initiated_by,
      'created' => $this->time->getCurrentTime(),
    ])->execute();
    unset($transaction);

    $this->logger->notice('Contract version @version proposed for replica @replica by the @side@user.', [
      '@version' => $version,
      '@replica' => $replica_id,
      '@side' => $initiated_by,
      '@user' => $uid ? sprintf(' (user %d)', $uid) : '',
    ]);
    return $contract;
  }

  /**
   * Makes a version active, on the replica's acknowledgement.
   *
   * @return bool
   *   TRUE if the version is now active; FALSE if it is unknown or was
   *   superseded by a newer proposal before it was acknowledged.
   */
  public function acknowledge(string $replica_id, int $version): bool {
    $row = $this->row($replica_id, $version);
    if ($row === NULL || $row['status'] === self::SUPERSEDED) {
      return FALSE;
    }
    if ($row['status'] === self::ACTIVE) {
      return TRUE;
    }
    $previous_mode = $this->getActive($replica_id)?->encryption();
    $new_mode = SubscriptionContract::fromArray(json_decode($row['terms'], TRUE))->encryption();
    // Events already sent went out under the previous version; this one
    // starts with the first that has not.
    $effective_from = max((int) $row['effective_from_sequence'], $this->outbox->lastSentSequence($replica_id) + 1);
    $transaction = $this->database->startTransaction();
    $this->database->update(self::TABLE)
      ->fields(['status' => self::SUPERSEDED])
      ->condition('replica_id', $replica_id)
      ->condition('status', self::ACTIVE)
      ->execute();
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::ACTIVE,
        'acknowledged' => $this->time->getCurrentTime(),
        'effective_from_sequence' => $effective_from,
      ])
      ->condition('replica_id', $replica_id)
      ->condition('version', $version)
      ->execute();
    $this->registry->setContractVersion($replica_id, $version);
    unset($transaction);
    $this->activateIfReady($replica_id);
    if ($previous_mode !== NULL && $previous_mode !== $new_mode) {
      if ($new_mode !== 'none') {
        $this->policy->recordChange($replica_id);
      }
      $this->logger->notice('Encryption for replica @replica changed from @old to @new with contract version @version (proposed by @side, user @uid).', [
        '@replica' => $replica_id,
        '@old' => $previous_mode,
        '@new' => $new_mode,
        '@version' => $version,
        '@side' => $row['initiated_by'],
        '@uid' => $row['created_by'] ?? 0,
      ]);
    }
    $this->logger->notice('Replica @replica acknowledged contract version @version, in force from event @sequence.', [
      '@replica' => $replica_id,
      '@version' => $version,
      '@sequence' => $effective_from,
    ]);
    return TRUE;
  }

  /**
   * Activates a pending replica once its contract and approval are in place.
   */
  public function activateIfReady(string $replica_id): void {
    $replica = $this->registry->get($replica_id);
    if ($replica === NULL || $replica->status !== Replica::PENDING || $replica->contractVersion === 0) {
      return;
    }
    $approval_required = (bool) $this->configFactory->get('content_relay_origin.settings')->get('pairing.require_approval');
    if ($replica->approved || !$approval_required) {
      $this->registry->setStatus($replica_id, Replica::ACTIVE);
    }
  }

  /**
   * The contract version in force.
   */
  public function getActive(string $replica_id): ?SubscriptionContract {
    return $this->load($replica_id, ['status' => self::ACTIVE]);
  }

  /**
   * The version waiting for acknowledgement, if any.
   */
  public function getPending(string $replica_id): ?SubscriptionContract {
    return $this->load($replica_id, ['status' => self::PENDING]);
  }

  /**
   * The newest version, whatever its status.
   */
  public function getLatest(string $replica_id): ?SubscriptionContract {
    return $this->load($replica_id, []);
  }

  /**
   * Every version, newest first, with its record.
   *
   * @return array<int, array{contract: \Drupal\content_relay\Contract\SubscriptionContract, status: string, created: int, created_by: int|null, initiated_by: string, acknowledged: int|null, effective_from: int}>
   *   History by version.
   */
  public function history(string $replica_id): array {
    $history = [];
    $rows = $this->database->select(self::TABLE, 'c')->fields('c')->condition('replica_id', $replica_id)->orderBy('version', 'DESC')->execute();
    foreach ($rows as $row) {
      $history[(int) $row->version] = [
        'contract' => SubscriptionContract::fromArray(json_decode($row->terms, TRUE)),
        'status' => $row->status,
        'created' => (int) $row->created,
        'created_by' => $row->created_by === NULL ? NULL : (int) $row->created_by,
        'initiated_by' => $row->initiated_by,
        'acknowledged' => $row->acknowledged === NULL ? NULL : (int) $row->acknowledged,
        'effective_from' => (int) $row->effective_from_sequence,
      ];
    }
    return $history;
  }

  /**
   * The bundles a replica receives or is about to, for recording events.
   */
  public function coversBundle(string $replica_id, string $entity_type_id, string $bundle): bool {
    foreach ([$this->getActive($replica_id), $this->getPending($replica_id)] as $contract) {
      if ($contract?->allowsBundle($entity_type_id, $bundle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Signs a contract for its replica.
   */
  public function sign(SubscriptionContract $contract): SignedEnvelope {
    return $this->signer->sign($contract->replicaId(), 'contract', $contract->toArray());
  }

  /**
   * Sends a replica its pending contract, or the active one when forced.
   *
   * The replica answers with a signed acknowledgement in the response body.
   *
   * @param string $replica_id
   *   The replica.
   * @param bool $force
   *   Send the active contract even if nothing is pending, for a replica
   *   that says it does not have it. Ignores the push interval.
   *
   * @return bool
   *   TRUE when the replica acknowledged it.
   */
  public function push(string $replica_id, bool $force = FALSE): bool {
    $replica = $this->registry->get($replica_id);
    if ($replica === NULL || !$replica->receivesEvents()) {
      return FALSE;
    }
    $contract = $this->getPending($replica_id) ?? ($force ? $this->getActive($replica_id) : NULL);
    if ($contract === NULL) {
      return FALSE;
    }
    $row = $this->row($replica_id, $contract->version());
    $now = $this->time->getCurrentTime();
    if (!$force && $row['last_push'] !== NULL && (int) $row['last_push'] > $now - self::PUSH_INTERVAL) {
      return FALSE;
    }
    $this->database->update(self::TABLE)->fields(['last_push' => $now])->condition('replica_id', $replica_id)->condition('version', $contract->version())->execute();

    try {
      $response = $this->httpClient->request('POST', $replica->endpoint, [
        'json' => $this->sign($contract)->toArray(),
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Contract version @version could not be sent to replica @replica: @message', [
        '@version' => $contract->version(),
        '@replica' => $replica_id,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($body['ack'] ?? NULL)) {
      $this->logger->warning('Replica @replica did not acknowledge contract version @version (HTTP @code).', [
        '@version' => $contract->version(),
        '@replica' => $replica_id,
        '@code' => $response->getStatusCode(),
      ]);
      return FALSE;
    }
    try {
      $message = $this->verifier->verify($body['ack']);
    }
    catch (VerificationException $e) {
      $this->logger->error('Replica @replica sent an acknowledgement that does not verify: @reason', [
        '@replica' => $replica_id,
        '@reason' => $e->reason,
      ]);
      return FALSE;
    }
    return $this->handleAcknowledgement($message);
  }

  /**
   * Acts on a verified contract_ack message.
   */
  public function handleAcknowledgement(Message $message): bool {
    if ($message->type !== 'contract_ack' || !is_int($message->body['version'] ?? NULL)) {
      return FALSE;
    }
    return $this->acknowledge($message->sender, $message->body['version']);
  }

  /**
   * Pushes every pending contract that is due, for cron.
   */
  public function pushPending(): void {
    $replica_ids = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['replica_id'])
      ->condition('status', self::PENDING)
      ->distinct()
      ->execute()
      ->fetchCol();
    foreach ($replica_ids as $replica_id) {
      $this->push($replica_id);
    }
  }

  /**
   * Loads the newest contract matching conditions.
   */
  protected function load(string $replica_id, array $conditions): ?SubscriptionContract {
    $query = $this->database->select(self::TABLE, 'c')->fields('c', ['terms'])->condition('replica_id', $replica_id);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    $terms = $query->orderBy('version', 'DESC')->range(0, 1)->execute()->fetchField();
    return $terms ? SubscriptionContract::fromArray(json_decode($terms, TRUE)) : NULL;
  }

  /**
   * A contract's record.
   */
  protected function row(string $replica_id, int $version): ?array {
    return $this->database->select(self::TABLE, 'c')->fields('c')->condition('replica_id', $replica_id)->condition('version', $version)->execute()->fetchAssoc() ?: NULL;
  }

}
