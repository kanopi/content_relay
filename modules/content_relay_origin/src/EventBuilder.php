<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay_origin\Controller\FileController;
use Drupal\content_relay_origin\File\FileKeys;
use Drupal\content_relay_origin\File\FileLinks;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\LatestRevision;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;

/**
 * Builds the event for an outbox row, from the entity as it is now.
 */
class EventBuilder {

  public function __construct(
    protected EntityRepositoryInterface $entityRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
    protected HashStore $hashStore,
    protected SyncScope $scope,
    protected NotSyncableFlags $flags,
    protected TimeInterface $time,
    protected FileLinks $fileLinks,
    protected FileChecksums $checksums,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected FileKeys $fileKeys,
    protected TranslationBlocks $translationBlocks,
  ) {}

  /**
   * Whether an item has no translation in the languages a replica gets.
   *
   * Such an item is not sent to the replica at all. Language-neutral items
   * always have one.
   */
  public function hasNoLanguagesFor(ContentEntityInterface $entity, SubscriptionContract $contract): bool {
    return $this->languages($entity, $contract, $contract->replicaId())[0] === [];
  }

  /**
   * The translations of an item a replica gets, and those kept from it.
   *
   * @return array{0: string[], 1: string[]}
   *   Langcodes sent, and langcodes withheld (blocked for this replica by
   *   either side), both among the languages the replica gets.
   */
  public function languages(ContentEntityInterface $entity, SubscriptionContract $contract, string $replica_id): array {
    $filter = $contract->languagesFor($entity->getEntityTypeId(), $entity->bundle());
    $blocked = $this->translationBlocks->forReplica($entity->getEntityTypeId(), (string) $entity->uuid(), $replica_id);
    $sent = $withheld = [];
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      $langcode = (string) $langcode;
      if (!GenericContentEntityNormalizer::wanted($langcode, $filter)) {
        continue;
      }
      if (isset($blocked[$langcode])) {
        $withheld[] = $langcode;
      }
      else {
        $sent[] = $langcode;
      }
    }
    return [$sent, $withheld];
  }

  /**
   * Builds the event for a row, or NULL when there is nothing to send.
   *
   * Nothing is sent for a create or update whose entity has since been
   * deleted (the delete row follows), or for a delete whose entity exists
   * again (it was replayed after the entity came back), unless the delete was
   * recorded to remove the replica's copy of an item marked not syncable.
   *
   * @param array $row
   *   The outbox row.
   * @param \Drupal\content_relay\Contract\SubscriptionContract $contract
   *   The contract it is sent under, which decides the languages included.
   * @param int|null $previous_sequence
   *   The sequence sent to this replica before this one.
   */
  public function build(array $row, SubscriptionContract $contract, ?int $previous_sequence): ?SyncEvent {
    $operation = Operation::from($row['operation']);
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($row['entity_type'], $row['entity_uuid']);
    // The latest revision, drafts included.
    $entity = $entity instanceof ContentEntityInterface ? LatestRevision::of($entity, $this->entityTypeManager) : NULL;

    $process = $row['process'] === NULL ? NULL : ProcessingMode::tryFrom($row['process']);
    if (!$operation->carriesSnapshot()) {
      if ($operation === Operation::Delete && $entity !== NULL && $row['reason'] !== Outbox::REASON_NOT_SYNCABLE) {
        return NULL;
      }
      return new SyncEvent(
        eventId: $row['event_id'],
        sequence: (int) $row['sequence'],
        operation: $operation,
        entityType: $row['entity_type'],
        bundle: $row['bundle'],
        entityUuid: $row['entity_uuid'],
        sourceRevision: (int) $row['source_revision'],
        revisionable: $this->isRevisionable($row['entity_type']),
        sourceChanged: NULL,
        defaultLangcode: 'und',
        langcodes: [],
        hashes: NULL,
        snapshot: NULL,
        contractVersion: $contract->version(),
        created: $this->time->getCurrentTime(),
        process: $process,
        previousSequence: $previous_sequence,
      );
    }

    if ($entity === NULL) {
      return NULL;
    }
    [$langcodes, $withheld] = $this->languages($entity, $contract, $row['replica_id']);
    $context = new NormalizationContext($withheld === [] ? $contract->languagesFor($entity->getEntityTypeId(), $entity->bundle()) : $langcodes);
    $snapshot = $this->serializer->normalize($entity, $context);
    $hashes = $this->hasher->hash($snapshot);
    [$dependencies, $external] = $this->classifyReferences(array_values($context->references), $contract, $row['replica_id']);
    $revisionable = $entity->getEntityType()->isRevisionable();
    $revision = $revisionable ? (int) $entity->getRevisionId() : (int) ($this->hashStore->latest($entity->getEntityTypeId(), $entity->uuid())['revision'] ?? 1);

    return new SyncEvent(
      eventId: $row['event_id'],
      sequence: (int) $row['sequence'],
      operation: $operation,
      entityType: $entity->getEntityTypeId(),
      bundle: $entity->bundle(),
      entityUuid: $entity->uuid(),
      sourceRevision: $revision,
      revisionable: $revisionable,
      sourceChanged: $entity instanceof EntityChangedInterface ? (int) $entity->getChangedTime() : NULL,
      defaultLangcode: $snapshot['default_langcode'],
      langcodes: array_map('strval', array_keys($snapshot['translations'])),
      hashes: $hashes,
      snapshot: $snapshot,
      contractVersion: $contract->version(),
      created: $this->time->getCurrentTime(),
      dependencies: $dependencies,
      files: $this->files($entity, $row['replica_id'], $contract->encryption() !== 'none'),
      process: $process,
      previousSequence: $previous_sequence,
      externalReferences: $external,
      unresolved: $context->unresolved,
      withheldLangcodes: $withheld,
    );
  }

  /**
   * The binaries a replica fetches for an event: a file entity's own.
   *
   * When the contract is encrypted the download is too, and the metadata
   * carries the file's key; the event itself is then sealed.
   *
   * @return array[]
   *   File metadata with a signed download link; empty for other entities and
   *   for a file missing from storage, whose entity still goes.
   */
  protected function files(ContentEntityInterface $entity, string $replica_id, bool $encrypted): array {
    if (!$entity instanceof FileInterface) {
      return [];
    }
    $checksum = $this->checksums->forUri($entity->getFileUri());
    if ($checksum === NULL) {
      return [];
    }
    $key_id = $encrypted ? $this->fileKeys->currentKeyId() : NULL;
    $file_key = $key_id === NULL ? NULL : $this->fileKeys->derive($key_id, (string) $entity->uuid(), $replica_id, $checksum['sha256']);
    return [FileController::metadata($entity, $checksum, $this->fileLinks->build($entity, $replica_id, $key_id), $this->fileUrlGenerator, $file_key)];
  }

  /**
   * Splits referenced entities into those the replica gets and the rest.
   *
   * A reference to something the replica subscribes to is a dependency: it is
   * sent first, and the replica waits for it if it has not arrived. Anything
   * else (an entity type or bundle outside the contract, one the origin does
   * not publish, or an item marked not syncable for the replica) will never
   * be sent, and the replica is told so.
   *
   * @param array<array{entity_type: string, uuid: string}> $references
   *   Referenced entities.
   * @param \Drupal\content_relay\Contract\SubscriptionContract $contract
   *   The contract in force.
   * @param string $replica_id
   *   The replica the event is for.
   *
   * @return array{0: array, 1: array}
   *   Dependencies and external references.
   */
  protected function classifyReferences(array $references, SubscriptionContract $contract, string $replica_id): array {
    $by_type = [];
    foreach ($references as $reference) {
      $by_type[$reference['entity_type']][] = $reference['uuid'];
    }
    $dependencies = $external = [];
    foreach ($by_type as $entity_type_id => $uuids) {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $uuid_key = (string) $this->entityTypeManager->getDefinition($entity_type_id)->getKey('uuid');
      $loaded = [];
      foreach ($storage->loadByProperties([$uuid_key => $uuids]) as $entity) {
        $loaded[$entity->uuid()] = $entity;
      }
      foreach ($uuids as $uuid) {
        $target = $loaded[$uuid] ?? NULL;
        $reference = ['entity_type' => $entity_type_id, 'uuid' => $uuid];
        if ($target !== NULL && $this->scope->canSend($target) && $contract->allowsBundle($entity_type_id, $target->bundle()) && !$this->flags->isBlocked($entity_type_id, $uuid, $replica_id)) {
          $dependencies[] = $reference;
        }
        else {
          $external[] = $reference;
        }
      }
    }
    return [$dependencies, $external];
  }

  /**
   * Whether an entity type has revisions.
   */
  protected function isRevisionable(string $entity_type_id): bool {
    return $this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable();
  }

}
