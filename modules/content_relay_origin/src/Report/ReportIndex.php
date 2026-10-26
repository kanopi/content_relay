<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Report;

use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\HashStore;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\ReplicaItems;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\SyncScope;
use Drupal\content_relay_origin\TranslationBlocks;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The sync status report's table: one row per item, translation and replica.
 *
 * Everything in it is derived from the event log, the hash store, what each
 * replica answered and the not syncable flags, and is recomputed for an item
 * whenever one of those changes for it. A table of its own, rather than a
 * query across those at view time, is what lets the report be a plain View
 * with filters and sorts on every column, over every entity type at once,
 * including items never sent.
 *
 * Rows exist only for replicas whose contract covers the item's bundle, and
 * only for the languages that replica receives.
 */
class ReportIndex {

  public const TABLE = 'content_relay_report';

  public const IN_SYNC = 'in_sync';
  public const OUT_OF_SYNC = 'out_of_sync';
  public const PENDING = 'pending';
  public const ACCEPTED = 'accepted';
  public const FAILED = 'failed';
  public const FAILED_ON_REPLICA = 'failed_on_replica';
  public const DEAD_LETTERED = 'dead_lettered';
  public const CONFLICT = 'conflict';
  public const NOT_SYNCABLE = 'not_syncable';
  public const DETACHED_BY_REPLICA = 'detached_by_replica';
  public const NEVER_SENT = 'never_sent';
  // The item has no translation in the languages the replica gets.
  public const NO_LANGUAGES = 'no_languages';

  /**
   * Translation states.
   */
  public const TRANSLATION_PRESENT = 'present';
  public const TRANSLATION_MISSING = 'missing';
  public const TRANSLATION_NEVER_SENT = 'never_sent';

  public function __construct(
    protected Connection $database,
    protected EntityRepositoryInterface $entityRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HashStore $hashStore,
    protected ReplicaItems $items,
    protected Outbox $outbox,
    protected NotSyncableFlags $flags,
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected SyncScope $scope,
    protected TimeInterface $time,
    protected TranslationBlocks $translationBlocks,
  ) {}

  /**
   * Status labels, by status.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels.
   */
  public static function statusLabels(): array {
    return [
      self::IN_SYNC => new TranslatableMarkup('In sync'),
      self::OUT_OF_SYNC => new TranslatableMarkup('Out of sync'),
      self::PENDING => new TranslatableMarkup('Pending'),
      self::ACCEPTED => new TranslatableMarkup('Accepted (queued on replica)'),
      self::FAILED => new TranslatableMarkup('Failed'),
      self::FAILED_ON_REPLICA => new TranslatableMarkup('Failed on replica'),
      self::DEAD_LETTERED => new TranslatableMarkup('Dead-lettered'),
      self::CONFLICT => new TranslatableMarkup('Conflict'),
      self::NOT_SYNCABLE => new TranslatableMarkup('Not synced (stopped)'),
      self::DETACHED_BY_REPLICA => new TranslatableMarkup('Detached by replica'),
      self::NEVER_SENT => new TranslatableMarkup('Never sent'),
      self::NO_LANGUAGES => new TranslatableMarkup('No languages for this replica'),
    ];
  }

  /**
   * Recomputes the rows of one item.
   */
  public function refresh(string $entity_type_id, string $uuid): void {
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityTypeManager->hasDefinition($entity_type_id) ? $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid) : NULL;
    if (!$entity instanceof ContentEntityInterface) {
      $this->remove($entity_type_id, $uuid);
      return;
    }
    $this->refreshEntity($entity);
  }

  /**
   * Recomputes the rows of one item from the entity.
   */
  public function refreshEntity(ContentEntityInterface $entity): void {
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = (string) $entity->uuid();
    if (!$this->scope->applies($entity)) {
      $this->remove($entity_type_id, $uuid);
      return;
    }
    $latest = $this->hashStore->latest($entity_type_id, $uuid) ?? $this->hashStore->record($entity);
    $keep = [];
    foreach ($this->registry->getAll() as $replica) {
      foreach ($this->rowsFor($entity, $replica, $latest) as $row) {
        $this->database->merge(self::TABLE)
          ->keys([
            'entity_type' => $entity_type_id,
            'uuid' => $uuid,
            'replica_id' => $replica->id,
            'langcode' => $row['langcode'],
          ])
          ->fields($row)
          ->execute();
        $keep[] = $replica->id . '|' . $row['langcode'];
      }
    }
    // Rows for replicas or languages that no longer apply.
    $existing = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['id', 'replica_id', 'langcode'])
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAll();
    $stale = [];
    foreach ($existing as $row) {
      if (!in_array($row->replica_id . '|' . $row->langcode, $keep, TRUE)) {
        $stale[] = $row->id;
      }
    }
    if ($stale !== []) {
      $this->database->delete(self::TABLE)->condition('id', $stale, 'IN')->execute();
    }
  }

  /**
   * An item's rows for its default translation, by replica.
   *
   * @return array<string, array>
   *   Rows.
   */
  public function itemRows(string $entity_type_id, string $uuid): array {
    $rows = $this->database->select(self::TABLE, 'r')
      ->fields('r')
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->condition('default_translation', 1)
      ->execute()
      ->fetchAllAssoc('replica_id');
    return array_map(fn (object $row) => (array) $row, $rows);
  }

  /**
   * Row IDs matching the report's main filters, for Drush.
   *
   * @param array $filters
   *   Any of: entity_type, bundle, status (a list), replica_id, langcode,
   *   any_out_of_sync (bool).
   *
   * @return int[]
   *   Row IDs.
   */
  public function rowIds(array $filters): array {
    $query = $this->database->select(self::TABLE, 'r')->fields('r', ['id']);
    foreach (['entity_type', 'bundle', 'replica_id', 'langcode'] as $key) {
      if (($filters[$key] ?? '') !== '') {
        $query->condition($key, $filters[$key]);
      }
    }
    if (!empty($filters['status'])) {
      $query->condition('status', (array) $filters['status'], 'IN');
    }
    if (!empty($filters['any_out_of_sync'])) {
      $query->condition('item_out_of_sync', 1);
    }
    return array_map('intval', $query->orderBy('id')->execute()->fetchCol());
  }

  /**
   * Removes an item's rows.
   */
  public function remove(string $entity_type_id, string $uuid): void {
    $this->database->delete(self::TABLE)
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute();
  }

  /**
   * Removes every row for a replica.
   */
  public function removeReplica(string $replica_id): void {
    $this->database->delete(self::TABLE)->condition('replica_id', $replica_id)->execute();
  }

  /**
   * IDs of published entities of a bundle, in ID order, for rebuilding.
   *
   * @return array<int|string, int|string>
   *   Entity IDs.
   */
  public function entityIds(string $entity_type_id, string $bundle, int|string|null $after, int $limit): array {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $id_key = (string) $definition->getKey('id');
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
      ->accessCheck(FALSE)
      ->sort($id_key)
      ->range(0, $limit);
    if ($definition->hasKey('bundle')) {
      $query->condition((string) $definition->getKey('bundle'), $bundle);
    }
    if ($after !== NULL) {
      $query->condition($id_key, $after, '>');
    }
    return $query->execute();
  }

  /**
   * Recomputes the rows of several entities of one type.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param array $ids
   *   Entity IDs.
   */
  public function refreshMultiple(string $entity_type_id, array $ids): void {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof ContentEntityInterface) {
        $this->refreshEntity($entity);
      }
    }
    $storage->resetCache($ids);
  }

  /**
   * The rows for an item on one replica.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The item.
   * @param \Drupal\content_relay_origin\Replica $replica
   *   The replica.
   * @param array{revision: int, hashes: \Drupal\content_relay\Hash\RevisionHashes, unresolved: array} $latest
   *   The item's current revision and hashes.
   *
   * @return array[]
   *   Rows without their keys, plus langcode.
   */
  protected function rowsFor(ContentEntityInterface $entity, Replica $replica, array $latest): array {
    $entity_type_id = $entity->getEntityTypeId();
    $uuid = (string) $entity->uuid();
    if (!$replica->receivesEvents() || !$this->contracts->coversBundle($replica->id, $entity_type_id, $entity->bundle())) {
      return [];
    }
    $contract = $this->contracts->getActive($replica->id) ?? $this->contracts->getPending($replica->id);
    $languages = $contract?->languagesFor($entity_type_id, $entity->bundle());
    $excluded = $contract?->excludedLanguages() ?? [];
    $origin_hashes = $latest['hashes'];
    $langcodes = array_values(array_filter(
      array_map('strval', array_keys($origin_hashes->translations)),
      fn (string $langcode) => GenericContentEntityNormalizer::wanted($langcode, $languages, $excluded),
    ));

    $item = $this->items->get($replica->id, $entity_type_id, $uuid);
    $replica_hashes = $item === NULL || $item['status'] === ReplicaItems::DELETED || $item['translation_hashes'] === NULL
      ? NULL
      : (json_decode($item['translation_hashes'], TRUE) ?: []);
    $row = $this->outbox->latestFor($replica->id, $entity_type_id, $uuid);
    $flag = $this->flags->getBlocking($entity_type_id, $uuid, $replica->id);

    $common = [
      'entity_id' => (string) $entity->id(),
      'bundle' => $entity->bundle(),
      'origin_revision' => $latest['revision'],
      'not_syncable' => (int) ($flag !== NULL),
      'detached_by' => NULL,
      'detached_at' => NULL,
      'last_event' => $row === NULL ? NULL : (int) $row['id'],
      'last_attempt' => $row !== NULL && (int) $row['attempts'] > 0 ? (int) $row['changed'] : NULL,
      'last_success' => $this->outbox->lastSuccessFor($replica->id, $entity_type_id, $uuid) ?? ($item === NULL ? NULL : (int) $item['updated']),
      'last_error' => NULL,
      'unresolved_files' => count(array_filter($latest['unresolved'], fn ($u) => ($u['entity_type'] ?? NULL) === 'file')),
    ];

    // What applies to the item as a whole, whatever the translation.
    $item_status = NULL;
    if ($flag !== NULL) {
      $item_status = self::NOT_SYNCABLE;
      $common['detached_at'] = (int) $flag['created'];
      $common['detached_by'] = $this->userName((int) $flag['uid']);
    }
    elseif ($item !== NULL && $item['detached_at'] !== NULL) {
      $item_status = self::DETACHED_BY_REPLICA;
      $common['detached_at'] = (int) $item['detached_at'];
      $common['detached_by'] = $item['detached_by'];
    }
    elseif ($row !== NULL) {
      $item_status = match ($row['status']) {
        EventStatus::QUEUED => self::PENDING,
        EventStatus::FAILED => self::FAILED,
        EventStatus::ACCEPTED => self::ACCEPTED,
        EventStatus::FAILED_ON_REPLICA => self::FAILED_ON_REPLICA,
        EventStatus::DEAD_LETTER => self::DEAD_LETTERED,
        EventStatus::STALE => $row['result_code'] === 'conflict' ? self::CONFLICT : NULL,
        default => NULL,
      };
      if (in_array($item_status, [self::FAILED, self::FAILED_ON_REPLICA, self::DEAD_LETTERED, self::CONFLICT], TRUE)) {
        $common['last_error'] = mb_substr(trim(($row['last_http_status'] ? 'HTTP ' . $row['last_http_status'] . ' ' : '') . ($row['result_code'] ?? '')), 0, 255) ?: NULL;
      }
    }

    // Hashes from an older algorithm cannot be compared with current ones;
    // the revision says whether the replica has the current version.
    $comparable = $item !== NULL && (int) ($item['algorithm'] ?? RevisionHasher::CURRENT_VERSION) === $origin_hashes->algorithmVersion;
    $same_revision = $item !== NULL && (int) ($item['revision'] ?? -1) === $latest['revision'];

    $rows = [];
    $out_of_sync = FALSE;
    $blocked = $this->translationBlocks->forReplica($entity_type_id, $uuid, $replica->id);
    foreach ($langcodes as $langcode) {
      $replica_hash = $replica_hashes[$langcode] ?? NULL;
      if ($replica_hashes === NULL) {
        $translation_status = self::TRANSLATION_NEVER_SENT;
        $status = self::NEVER_SENT;
      }
      elseif ($replica_hash === NULL) {
        $translation_status = self::TRANSLATION_MISSING;
        $status = self::OUT_OF_SYNC;
      }
      else {
        $translation_status = self::TRANSLATION_PRESENT;
        $in_sync = $comparable ? hash_equals($origin_hashes->translations[$langcode], $replica_hash) : $same_revision;
        $status = $in_sync ? self::IN_SYNC : self::OUT_OF_SYNC;
      }
      // A translation kept from the replica is neither in nor out of sync.
      $block = $blocked[$langcode] ?? NULL;
      if ($block !== NULL) {
        $status = $block['side'] === TranslationBlocks::ORIGIN ? self::NOT_SYNCABLE : self::DETACHED_BY_REPLICA;
      }
      $out_of_sync = $out_of_sync || !in_array($status, [self::IN_SYNC, self::NOT_SYNCABLE, self::DETACHED_BY_REPLICA], TRUE);
      $translation = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity;
      $rows[] = [
        'langcode' => $langcode,
        'detached_by' => $block['by'] ?? $common['detached_by'],
        'detached_at' => $block['created'] ?? $common['detached_at'],
        'default_translation' => (int) ($langcode === $entity->getUntranslated()->language()->getId()),
        'label' => mb_substr((string) $translation->label(), 0, 255),
        'origin_hash' => $origin_hashes->translations[$langcode],
        'replica_hash' => $replica_hash,
        'status' => $item_status ?? $status,
        'translation_status' => $translation_status,
        'changed' => $translation instanceof EntityChangedInterface ? (int) $translation->getChangedTime() : NULL,
      ] + $common;
    }
    if ($langcodes === []) {
      // Not sent at all, and not out of sync either: one row says why.
      $default = $entity->getUntranslated();
      $rows[] = [
        'langcode' => $default->language()->getId(),
        'default_translation' => 1,
        'label' => mb_substr((string) $default->label(), 0, 255),
        'origin_hash' => $origin_hashes->translations[$default->language()->getId()] ?? NULL,
        'replica_hash' => NULL,
        'status' => $item_status === self::NOT_SYNCABLE ? $item_status : self::NO_LANGUAGES,
        'translation_status' => self::TRANSLATION_NEVER_SENT,
        'changed' => $default instanceof EntityChangedInterface ? (int) $default->getChangedTime() : NULL,
      ] + $common;
    }
    foreach ($rows as &$built) {
      $built['item_out_of_sync'] = (int) $out_of_sync;
    }
    return $rows;
  }

  /**
   * A user's name, for "who detached it".
   */
  protected function userName(int $uid): string {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account ? (string) $account->getDisplayName() : (string) $uid;
  }

}
