<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\content_relay_replica\Crypto\OriginPolicy;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\Processing\ReceivedEvents;
use Drupal\content_relay_replica\Schema\SchemaWatcher;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Ends a pairing with an origin.
 *
 * What came from the origin is either kept, as this site's own content, or
 * deleted. Detached items are this site's already and are always kept. The
 * origin is told, so it stops sending; if it cannot be reached, unpairing
 * goes ahead and its events are refused from then on. Everything kept about
 * the pairing (origin, contract, stream, failures, files, reconciliation,
 * pending notices) goes, and the pairing's settings with it.
 *
 * Deleting is done a page at a time, so a form can run it as a batch.
 */
class Unpairer {

  /**
   * Items deleted per page.
   */
  public const PAGE = 50;

  /**
   * State kept per pairing.
   */
  protected const STATE_KEYS = [
    OriginPeer::STATE_KEY,
    OriginClient::STATE_PENDING,
    SubscriptionManager::PENDING_PROPOSAL,
    LanguageSubscription::PENDING,
    LanguageSubscription::BACKFILL,
    LanguageSubscription::REMOVAL,
    OriginPolicy::STATE_CHANGED,
    SchemaWatcher::STATE,
    'content_relay_replica.reconciled',
  ];

  public function __construct(
    protected ActivePairing $active,
    protected OriginPeer $origin,
    protected OriginClient $originClient,
    protected Connection $database,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {}

  /**
   * Unpairs in one go.
   *
   * @param string $pairing
   *   The pairing.
   * @param bool $delete
   *   Whether to delete what came from the origin, rather than keep it.
   *
   * @return array{notified: bool, deleted: int, kept: int}
   *   Whether the origin heard, and what happened to its items.
   */
  public function unpair(string $pairing, bool $delete): array {
    $notified = $this->notifyOrigin($pairing);
    $deleted = 0;
    if ($delete) {
      do {
        $page = $this->deletePage($pairing);
        $deleted += $page['deleted'];
      } while ($page['remaining'] > 0 && $page['deleted'] > 0);
    }
    return ['notified' => $notified, 'deleted' => $deleted, 'kept' => $this->finish($pairing)];
  }

  /**
   * Tells the origin this site no longer receives from it.
   *
   * @return bool
   *   TRUE if the origin heard.
   */
  public function notifyOrigin(string $pairing): bool {
    if ($this->origin->get($pairing) === NULL) {
      return FALSE;
    }
    return $this->active->run($pairing, fn () => $this->originClient->send('unpair_notice', ['at' => $this->time->getCurrentTime()]));
  }

  /**
   * Items that came from a pairing's origin, by whether they are detached.
   *
   * @return array{received: int, detached: int}
   *   Counts.
   */
  public function count(string $pairing): array {
    $site = $this->origin->getSiteId($pairing);
    if ($site === NULL) {
      return ['received' => 0, 'detached' => 0];
    }
    $detached = $this->detachedCondition();
    $total = (int) $this->database->select(SyncStateStore::TABLE, 's')
      ->condition('source_site', $site)
      ->countQuery()->execute()->fetchField();
    $kept = (int) $this->database->select(SyncStateStore::TABLE, 's')
      ->condition('source_site', $site)
      ->condition('status', $detached, 'IN')
      ->countQuery()->execute()->fetchField();
    return ['received' => $total - $kept, 'detached' => $kept];
  }

  /**
   * Deletes a page of what came from a pairing's origin.
   *
   * @return array{deleted: int, remaining: int}
   *   Items deleted, and items left to delete.
   */
  public function deletePage(string $pairing): array {
    // Hooks on the deletes see the pairing they are for.
    if ($this->active->current() !== $pairing) {
      return $this->active->run($pairing, fn () => $this->deletePage($pairing));
    }
    $site = $this->origin->getSiteId($pairing);
    if ($site === NULL) {
      return ['deleted' => 0, 'remaining' => 0];
    }
    $rows = $this->database->select(SyncStateStore::TABLE, 's')
      ->fields('s', ['entity_type', 'uuid'])
      ->condition('source_site', $site)
      ->condition('status', $this->detachedCondition(), 'NOT IN')
      ->range(0, self::PAGE)
      ->execute()
      ->fetchAll();
    $deleted = 0;
    foreach ($rows as $row) {
      $entity_type_id = (string) $row->entity_type;
      $uuid = (string) $row->uuid;
      try {
        $found = $this->entityTypeManager->getStorage($entity_type_id)->loadByProperties(['uuid' => $uuid]);
      }
      catch (PluginNotFoundException) {
        // An entity type since uninstalled leaves nothing to delete.
        $found = [];
      }
      foreach ($found as $entity) {
        // As a sync delete: passed on to this site's own replicas, if any,
        // but not back to an origin.
        if ($entity instanceof ContentEntityInterface) {
          $entity->setSyncing(TRUE);
        }
        $entity->delete();
        $deleted++;
      }
      $this->database->delete(SyncStateStore::TABLE)
        ->condition('entity_type', $entity_type_id)
        ->condition('uuid', $uuid)
        ->execute();
    }
    return ['deleted' => $deleted, 'remaining' => $this->count($pairing)['received']];
  }

  /**
   * Releases what is left and forgets the pairing.
   *
   * @return int
   *   Items kept as this site's own.
   */
  public function finish(string $pairing): int {
    if ($this->active->current() !== $pairing) {
      return $this->active->run($pairing, fn () => $this->finish($pairing));
    }
    $site = $this->origin->getSiteId($pairing);
    $kept = 0;
    if ($site !== NULL) {
      $kept = (int) $this->database->delete(SyncStateStore::TABLE)->condition('source_site', $site)->execute();
      $failures = $this->database->select(FailedEventStore::TABLE, 'f')->fields('f', ['id'])->condition('source_site', $site)->execute()->fetchCol();
      if ($failures !== []) {
        $this->database->delete(FailedEventStore::DEPENDENCY_TABLE)->condition('failure_id', $failures, 'IN')->execute();
        $this->database->delete(FailedEventStore::TABLE)->condition('id', $failures, 'IN')->execute();
      }
      foreach ([ReceivedEvents::TABLE, StreamTracker::TABLE] as $table) {
        $this->database->delete($table)->condition('source_site', $site)->execute();
      }
    }
    $files = $this->database->select(FileSync::TABLE, 'f')->fields('f', ['uuid'])->condition('pairing', $pairing)->execute()->fetchCol();
    if ($files !== []) {
      $this->database->delete(FileSync::WAIT_TABLE)->condition('file_uuid', $files, 'IN')->execute();
      $this->database->delete(FileSync::TABLE)->condition('uuid', $files, 'IN')->execute();
    }
    foreach ([ContractStore::TABLE, Reconciler::TABLE] as $table) {
      $this->database->delete($table)->condition('pairing', $pairing)->execute();
    }
    $this->state->deleteMultiple(array_map(fn (string $key) => $this->active->stateKey($key, $pairing), self::STATE_KEYS));
    $this->entityTypeManager->getStorage('content_relay_origin_pairing')->load($pairing)?->delete();
    $this->cacheTagsInvalidator->invalidateTags([FailedEventStore::CACHE_TAG]);
    $this->logger->notice('Unpaired from the origin of pairing @pairing; @kept item(s) kept as local content.', [
      '@pairing' => $pairing,
      '@kept' => $kept,
    ]);
    return $kept;
  }

  /**
   * Statuses of items already this site's own.
   *
   * @return string[]
   *   Statuses.
   */
  protected function detachedCondition(): array {
    return [SyncStatus::DETACHED_BY_REPLICA, SyncStatus::DETACHED_BY_ORIGIN];
  }

}
