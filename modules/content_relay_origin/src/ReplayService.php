<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Event\Operation;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Replays dead-lettered and failed events.
 *
 * A replay is a new event, decided from the entity as it is now: an update if
 * the entity exists, a delete if it is still gone. The old row is marked
 * replayed and the new one points back at it. Nothing from the old attempt is
 * resent, so a replay can never put an outdated version on a replica, and a
 * replayed delete cannot remove an entity that has since come back.
 */
class ReplayService {

  public function __construct(
    protected Outbox $outbox,
    protected HashStore $hashStore,
    protected DeliveryScheduler $scheduler,
    protected EntityRepositoryInterface $entityRepository,
    protected AccountInterface $currentUser,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Replays one event log row.
   *
   * @return int
   *   The new row ID.
   *
   * @throws \InvalidArgumentException
   *   When the row does not exist or is not in a replayable state.
   */
  public function replay(int $row_id): int {
    $row = $this->outbox->load($row_id);
    if ($row === NULL || !in_array($row['status'], EventStatus::REPLAYABLE, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Event log entry %d cannot be replayed.', $row_id));
    }
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($row['entity_type'], $row['entity_uuid']);
    if ($entity instanceof ContentEntityInterface) {
      $operation = Operation::Update;
      $revision = $this->hashStore->latest($row['entity_type'], $row['entity_uuid'])['revision'] ?? NULL;
    }
    else {
      $operation = Operation::Delete;
      $revision = $row['source_revision'] === NULL ? NULL : (int) $row['source_revision'];
    }

    $new = $this->outbox->enqueue($operation, $row['entity_type'], $row['bundle'], $row['entity_uuid'], $revision, [$row['replica_id']], (int) $this->currentUser->id(), $row_id);
    if (!isset($new[$row['replica_id']])) {
      throw new \InvalidArgumentException(sprintf('Replica %s no longer receives events.', $row['replica_id']));
    }
    $this->outbox->update($row_id, ['status' => EventStatus::REPLAYED]);
    $this->scheduler->schedule([$row['replica_id']]);
    $this->logger->notice('@user replayed event @event (@type @uuid) to replica @replica as a @operation.', [
      '@user' => $this->currentUser->getAccountName(),
      '@event' => $row['event_id'],
      '@type' => $row['entity_type'],
      '@uuid' => $row['entity_uuid'],
      '@replica' => $row['replica_id'],
      '@operation' => $operation->value,
    ]);
    return $new[$row['replica_id']];
  }

}
