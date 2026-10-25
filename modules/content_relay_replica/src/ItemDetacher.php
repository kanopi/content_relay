<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;

/**
 * Detaches and reattaches synced items on this replica.
 *
 * A detached item keeps its sync state row, so this site still knows where
 * it came from and still refuses to let another item take its UUID, but no
 * event writes it any more. The origin is told, so it stops sending it.
 *
 * Reattaching checks for local edits first. Without any, the item is
 * attached and the origin sends its current version. With some, the caller
 * decides: accept the origin's version (the item is attached and the next
 * version from the origin replaces the edits, even if the origin's content
 * has not changed since) or keep local (it stays detached).
 */
class ItemDetacher {

  public function __construct(
    protected SyncStateStore $syncState,
    protected LocalChanges $localChanges,
    protected OriginClient $originClient,
    protected EntityRepositoryInterface $entityRepository,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Detaches an item.
   *
   * @return bool
   *   FALSE if it is not synced or already detached here.
   */
  public function detach(string $entity_type_id, string $uuid): bool {
    $state = $this->syncState->get($entity_type_id, $uuid);
    if ($state === NULL || $state['detached_at'] !== NULL) {
      return FALSE;
    }
    $now = $this->time->getCurrentTime();
    $this->syncState->save($entity_type_id, $uuid, [
      'detached_by' => (int) $this->currentUser->id(),
      'detached_at' => $now,
      'status' => SyncStatus::DETACHED_BY_REPLICA,
      'last_synced' => (int) $state['last_synced'],
    ]);
    $this->logger->notice('@user detached @type @uuid.', [
      '@user' => $this->currentUser->getAccountName(),
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
    ]);
    $this->originClient->notify('detach_notice', [
      'entity_type' => $entity_type_id,
      'uuid' => $uuid,
      'bundle' => $state['bundle'],
      'by' => $this->currentUser->getDisplayName(),
      'at' => $now,
    ]);
    return TRUE;
  }

  /**
   * The translations edited here since the item was last synced.
   *
   * @return string[]|null
   *   NULL when there are no local edits; see LocalChanges.
   */
  public function localEdits(string $entity_type_id, string $uuid): ?array {
    $state = $this->syncState->get($entity_type_id, $uuid);
    $entity = $state === NULL ? NULL : $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    return $entity === NULL ? NULL : $this->localChanges->editedTranslations($entity, $state);
  }

  /**
   * Reattaches an item.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param bool $accept_origin
   *   When the item was edited here: TRUE to take the origin's version, FALSE
   *   to keep the edits and stay detached. Ignored without local edits.
   *
   * @return bool
   *   TRUE if the item was reattached.
   */
  public function reattach(string $entity_type_id, string $uuid, bool $accept_origin = TRUE): bool {
    $state = $this->syncState->get($entity_type_id, $uuid);
    if ($state === NULL || $state['detached_at'] === NULL) {
      return FALSE;
    }
    $edited = $this->localEdits($entity_type_id, $uuid);
    if ($edited !== NULL && !$accept_origin) {
      $this->logger->notice('@user kept local edits to @type @uuid; it stays detached.', [
        '@user' => $this->currentUser->getAccountName(),
        '@type' => $entity_type_id,
        '@uuid' => $uuid,
      ]);
      return FALSE;
    }
    $status = SyncStatus::SYNCED;
    $error = NULL;
    $fields = [];
    if ($state['origin_detached_at'] !== NULL) {
      $status = SyncStatus::DETACHED_BY_ORIGIN;
    }
    elseif ($edited !== NULL) {
      // Not in sync, so the origin's version is applied when it arrives even
      // if it is the revision this site already recorded.
      $status = SyncStatus::ERROR;
      $error = 'Reattached with local edits; the origin\'s version replaces them.';
    }
    $entity = $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    if ($edited !== NULL && $entity !== NULL) {
      // The edits are accepted as replaceable: the copy as it is now becomes
      // the baseline, so the origin's version is not taken for a conflict.
      $fields = $this->localChanges->baseline($entity, $state);
    }
    $this->syncState->save($entity_type_id, $uuid, $fields + [
      'detached_by' => NULL,
      'detached_at' => NULL,
      'conflict_revision' => NULL,
      'conflict_at' => NULL,
      'status' => $status,
      'last_error' => $error,
      'last_synced' => (int) $state['last_synced'],
    ]);
    $this->logger->notice('@user reattached @type @uuid@edits.', [
      '@user' => $this->currentUser->getAccountName(),
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
      '@edits' => $edited === NULL ? '' : ', accepting the origin version over local edits',
    ]);
    $this->originClient->notify('attach_notice', [
      'entity_type' => $entity_type_id,
      'uuid' => $uuid,
      'bundle' => $state['bundle'],
      'by' => $this->currentUser->getDisplayName(),
      'at' => $this->time->getCurrentTime(),
    ]);
    return TRUE;
  }

  /**
   * The batch definition for detaching or reattaching many items.
   *
   * @param string[] $items
   *   Items as "entity_type:uuid".
   * @param string $operation
   *   The operation: detach or reattach.
   * @param bool $accept_origin
   *   For reattach: replace local edits with the origin's version.
   */
  public static function batch(array $items, string $operation, bool $accept_origin = FALSE): array {
    $operations = [];
    foreach (array_chunk($items, 25) as $chunk) {
      $operations[] = [[static::class, 'batchStep'], [$chunk, $operation, $accept_origin]];
    }
    return [
      'title' => $operation === 'detach' ? new TranslatableMarkup('Detaching items') : new TranslatableMarkup('Reattaching items'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
    ];
  }

  /**
   * Batch operation: detaches or reattaches a chunk of items.
   */
  public static function batchStep(array $items, string $operation, bool $accept_origin, &$context): void {
    $detacher = \Drupal::service('content_relay_replica.detacher');
    foreach ($items as $item) {
      [$entity_type_id, $uuid] = explode(':', (string) $item, 2) + [1 => ''];
      $done = $operation === 'detach'
        ? $detacher->detach($entity_type_id, $uuid)
        : $detacher->reattach($entity_type_id, $uuid, $accept_origin);
      $context['results'][$operation][$done ? 'done' : 'unchanged'] = ($context['results'][$operation][$done ? 'done' : 'unchanged'] ?? 0) + 1;
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    foreach ($results as $operation => $counts) {
      $messenger->addStatus(new TranslatableMarkup('@done item(s) @operation, @unchanged unchanged.', [
        '@done' => $counts['done'] ?? 0,
        '@operation' => $operation === 'detach' ? 'detached' : 'reattached',
        '@unchanged' => $counts['unchanged'] ?? 0,
      ]));
    }
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The operation did not finish.'));
    }
  }

}
