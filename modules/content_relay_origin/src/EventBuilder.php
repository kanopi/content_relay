<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

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
    protected TimeInterface $time,
  ) {}

  /**
   * Builds the event for a row, or NULL when there is nothing to send.
   *
   * Nothing is sent for a create or update whose entity has since been
   * deleted (the delete row follows), or for a delete whose entity exists
   * again (it was replayed after the entity came back).
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
    $entity = $entity instanceof ContentEntityInterface ? $entity : NULL;

    if (!$operation->carriesSnapshot()) {
      if ($operation === Operation::Delete && $entity !== NULL) {
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
        previousSequence: $previous_sequence,
      );
    }

    if ($entity === NULL) {
      return NULL;
    }
    $context = new NormalizationContext($contract->languagesFor($entity->getEntityTypeId(), $entity->bundle()));
    $snapshot = $this->serializer->normalize($entity, $context);
    $hashes = $this->hasher->hash($snapshot);
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
      dependencies: array_values($context->references),
      previousSequence: $previous_sequence,
    );
  }

  /**
   * Whether an entity type has revisions.
   */
  protected function isRevisionable(string $entity_type_id): bool {
    return $this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable();
  }

}
