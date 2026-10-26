<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\LatestRevision;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Checks one item on this site and on every replica that gets it.
 *
 * Here: the item is hashed again and compared with the stored hash, which
 * catches content changed without a save; a stale hash is replaced. On each
 * replica: it is asked, in a signed hash_request, what it last received and
 * whether its copy still hashes as it should. Each replica's answer updates
 * what this site records of it, so the status report reflects it at once.
 */
class ItemValidator {

  /**
   * Results per replica.
   */
  public const IN_SYNC = 'in_sync';
  public const BEHIND = 'behind';
  public const CHANGED_ON_REPLICA = 'changed_on_replica';
  public const MISSING = 'missing';
  public const DETACHED = 'detached';
  public const NOT_SYNCABLE = 'not_syncable';
  public const NO_LANGUAGES = 'no_languages';
  public const UNREACHABLE = 'unreachable';

  public function __construct(
    protected HashStore $hashStore,
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected NotSyncableFlags $flags,
    protected ReplicaItems $items,
    protected EventBuilder $eventBuilder,
    protected ReportIndex $report,
    protected MessageSigner $signer,
    protected MessageVerifier $verifier,
    protected ClientInterface $httpClient,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Validates an item everywhere.
   *
   * @return array{origin_stale: bool, revision: int, replicas: array<string, array{label: string, result: string, revision: int|null, differing: string[], detail: string|null}>}
   *   Whether the stored hash here was out of date, the current revision or
   *   version, and a result per replica.
   */
  public function validate(ContentEntityInterface $entity): array {
    $latest = LatestRevision::of($entity, $this->entityTypeManager);
    $entity_type_id = $latest->getEntityTypeId();
    $uuid = (string) $latest->uuid();
    $fresh = $this->hasher->hash($this->serializer->normalize($latest, new NormalizationContext()));
    $stored = $this->hashStore->latest($entity_type_id, $uuid);
    $stale = $stored === NULL || !hash_equals($stored['hashes']->entity, $fresh->entity);
    if ($stale) {
      $stored = $this->hashStore->record($latest);
      $this->logger->warning('Validation found the stored hash of @type @uuid out of date; it was recomputed.', [
        '@type' => $entity_type_id,
        '@uuid' => $uuid,
      ]);
    }

    $results = [];
    foreach ($this->registry->getAll() as $replica) {
      $contract = $this->contracts->getActive($replica->id);
      if (!$replica->receivesEvents() || $contract === NULL || !$contract->allowsBundle($entity_type_id, $latest->bundle())) {
        continue;
      }
      $result = [
        'label' => $replica->label,
        'result' => self::IN_SYNC,
        'revision' => NULL,
        'differing' => [],
        'detail' => NULL,
      ];
      [$sent] = $this->eventBuilder->languages($latest, $contract, $replica->id);
      if ($this->flags->isBlocked($entity_type_id, $uuid, $replica->id)) {
        $results[$replica->id] = ['result' => self::NOT_SYNCABLE] + $result;
        continue;
      }
      if ($sent === []) {
        $results[$replica->id] = ['result' => self::NO_LANGUAGES] + $result;
        continue;
      }
      $answer = $this->ask($replica, $entity_type_id, $uuid);
      if (is_string($answer)) {
        $results[$replica->id] = ['result' => self::UNREACHABLE, 'detail' => $answer] + $result;
        continue;
      }
      if (!($answer['synced'] ?? FALSE) || !($answer['present'] ?? FALSE)) {
        $results[$replica->id] = ['result' => self::MISSING] + $result;
        continue;
      }
      $result['revision'] = (int) ($answer['revision'] ?? 0);
      $received = is_array($answer['translation_hashes'] ?? NULL) ? $answer['translation_hashes'] : [];
      $skip = array_flip(is_array($answer['detached_translations'] ?? NULL) ? $answer['detached_translations'] : []);
      foreach ($sent as $langcode) {
        if (!isset($skip[$langcode]) && (!isset($received[$langcode]) || !hash_equals((string) $received[$langcode], (string) ($fresh->translations[$langcode] ?? '')))) {
          $result['differing'][] = $langcode;
        }
      }
      $result['result'] = match (TRUE) {
        !empty($answer['detached']) => self::DETACHED,
        !($answer['local_ok'] ?? FALSE) => self::CHANGED_ON_REPLICA,
        $result['differing'] !== [] => self::BEHIND,
        default => self::IN_SYNC,
      };
      $this->items->recordValidated($replica->id, $entity_type_id, $uuid, $latest->bundle(), $result['revision'], (int) ($answer['hash_algorithm'] ?? RevisionHasher::CURRENT_VERSION), $received);
      $results[$replica->id] = $result;
    }
    $this->report->refresh($entity_type_id, $uuid);
    return ['origin_stale' => $stale, 'revision' => $stored['revision'], 'replicas' => $results];
  }

  /**
   * Asks a replica about an item.
   *
   * @return array|string
   *   Its answer for the item, or why there is none.
   */
  protected function ask(Replica $replica, string $entity_type_id, string $uuid): array|string {
    try {
      $response = $this->httpClient->request('POST', $replica->endpoint, [
        'json' => $this->signer->sign($replica->id, 'hash_request', [
          'items' => [['entity_type' => $entity_type_id, 'uuid' => $uuid]],
        ])->toArray(),
        'headers' => ['Accept' => 'application/json'],
        'timeout' => 30,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      return $e->getMessage();
    }
    $body = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($body['hashes'] ?? NULL)) {
      return sprintf('HTTP %d', $response->getStatusCode());
    }
    try {
      $message = $this->verifier->verify($body['hashes']);
    }
    catch (VerificationException $e) {
      return sprintf('The answer does not verify: %s', $e->reason);
    }
    if ($message->type !== 'hashes' || $message->sender !== $replica->id) {
      return 'The answer is not from this replica.';
    }
    $item = $message->body['items'][$entity_type_id . ':' . $uuid] ?? NULL;
    return is_array($item) ? $item : 'The answer does not mention the item.';
  }

  /**
   * Labels for results.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels by result.
   */
  public static function resultLabels(): array {
    return [
      self::IN_SYNC => new TranslatableMarkup('In sync'),
      self::BEHIND => new TranslatableMarkup('Behind the origin'),
      self::CHANGED_ON_REPLICA => new TranslatableMarkup('Changed on the replica'),
      self::MISSING => new TranslatableMarkup('Missing on the replica'),
      self::DETACHED => new TranslatableMarkup('Detached by the replica'),
      self::NOT_SYNCABLE => new TranslatableMarkup('Syncing stopped'),
      self::NO_LANGUAGES => new TranslatableMarkup('No languages for this replica'),
      self::UNREACHABLE => new TranslatableMarkup('Could not be asked'),
    ];
  }

}
