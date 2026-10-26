<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Brings this site in line with the origin, for whatever events were missed.
 *
 * Reconciliation goes through every bundle this site receives, a page of the
 * origin's hashes at a time, and compares them with the sync state here:
 *
 * - An item here whose hashes differ from the origin's latest is pulled.
 * - An item the origin has and this site does not is pulled.
 * - An item this site has and the origin no longer does is deleted.
 * - An item the origin now keeps from this site is marked detached by the
 *   origin; one it sends again is marked attached and pulled.
 *
 * Only items with a sync state row are ever updated or deleted; content made
 * here is never touched. Items detached here are never pulled or updated,
 * and deleted only when this site follows deletes of detached items. Items in
 * conflict are left for a person. Every write goes through the apply service,
 * with its locks and checks, so a run is safe to repeat and changes nothing
 * when the sites are in sync.
 *
 * A run is done in steps that each read one page, so cron can spread a large
 * site over several runs and the admin page can drive it with a batch.
 *
 * A run acts on at most reconcile.max_items items itself (pulls, deletes,
 * detaches); the rest go into a queue that cron works through at the same
 * rate per cron run. So does anything the origin turns away because this
 * site is over its read limit; a run whose listing is turned away waits and
 * carries on at a later cron run. A new run does not start while the queue
 * still has items, so nothing is queued twice.
 *
 * Gap filling is the quick version, for a few events: the origin says what
 * the missed events were about and where those items stand now.
 */
class Reconciler {

  public const TABLE = 'content_relay_reconcile_run';

  public const QUEUE = 'content_relay_replica_reconcile';

  /**
   * What a step left: the run is done, has more to do, or must wait.
   */
  public const DONE = 'done';
  public const MORE = 'more';
  public const WAIT = 'wait';

  /**
   * Items per page of hashes.
   */
  protected const PAGE = 200;

  /**
   * Statuses of items a run does not pull.
   *
   * They wait for a person, or for something else to arrive.
   */
  protected const LEFT_ALONE = [
    SyncStatus::CONFLICT,
    SyncStatus::WAITING_FILES,
    SyncStatus::WAITING_DEPENDENCY,
  ];

  /**
   * Problems kept per run.
   */
  protected const MAX_MESSAGES = 50;

  public function __construct(
    protected Connection $database,
    protected OriginClient $originClient,
    protected OriginPeer $origin,
    protected ContractStore $contracts,
    protected SyncStateStore $syncState,
    protected SyncApplier $applier,
    protected Puller $puller,
    protected StreamTracker $streams,
    protected EntityRepositoryInterface $entityRepository,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected UuidInterface $uuid,
    protected LoggerInterface $logger,
    protected QueueFactory $queueFactory,
    protected ReceivingSettings $receiving,
    protected ActivePairing $active,
  ) {}

  /**
   * Items a run acts on itself, and the queue worker per cron run.
   */
  public function maxItems(): int {
    return max(1, (int) ($this->receiving->get('reconcile.max_items') ?? 100));
  }

  /**
   * Starts a run.
   *
   * @param string $trigger
   *   What started it: cron, manual or drush.
   *
   * @return int
   *   The run ID.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When this site has no contract to reconcile against, or items from
   *   the last run are still queued.
   */
  public function start(string $trigger): int {
    $contract = $this->contracts->getCurrent();
    if ($contract === NULL || $this->origin->get() === NULL) {
      throw new SubscriptionException('This site is not paired with an origin.');
    }
    $waiting = $this->queueFactory->get(self::QUEUE)->numberOfItems();
    if ($waiting > 0) {
      throw new SubscriptionException(sprintf('%d item(s) from the last run are still queued; cron works through them first.', $waiting));
    }
    $bundles = [];
    foreach ($contract->effectiveBundles() as $entity_type_id => $list) {
      foreach ($list as $bundle) {
        $bundles[] = [(string) $entity_type_id, (string) $bundle];
      }
    }
    return (int) $this->database->insert(self::TABLE)->fields([
      'pairing' => $this->active->id(),
      'started' => $this->time->getCurrentTime(),
      'trigger' => $trigger,
      'uid' => in_array($trigger, ['cron', 'backfill'], TRUE) ? NULL : (int) $this->currentUser->id(),
      'status' => 'running',
      'counts' => '{}',
      'messages' => '[]',
      'cursor' => json_encode([
        'bundles' => $bundles,
        'index' => 0,
        'phase' => 'list',
        'after' => '',
        'contract_version' => $contract->version(),
        'acted' => 0,
        'wait_until' => 0,
        'backfill' => $trigger === 'backfill',
      ]),
    ])->execute();
  }

  /**
   * Does the next step of a run: one page of one bundle.
   *
   * @return string
   *   DONE, MORE, or WAIT when the origin asked this site to wait.
   *
   * @phpstan-impure
   */
  public function step(int $run_id): string {
    $run = $this->load($run_id);
    if ($run === NULL || $run['status'] !== 'running') {
      return self::DONE;
    }
    $cursor = $run['cursor'];
    $counts = $run['counts'];
    $messages = $run['messages'];
    if (($cursor['wait_until'] ?? 0) > $this->time->getCurrentTime()) {
      return self::WAIT;
    }
    if (!isset($cursor['bundles'][$cursor['index']])) {
      $this->finish($run_id, $counts, $messages, 'completed');
      return self::DONE;
    }
    [$entity_type_id, $bundle] = $cursor['bundles'][$cursor['index']];
    $next = self::MORE;
    $acted = (int) ($cursor['acted'] ?? 0);
    try {
      if ($cursor['phase'] === 'list') {
        $page = $this->originClient->hashes($entity_type_id, $bundle, $cursor['after'], self::PAGE);
        if ($page['contract_version'] !== NULL && $page['contract_version'] !== $cursor['contract_version'] && empty($cursor['mismatch_noted'])) {
          $messages[] = sprintf('The origin uses contract version %d; this site has version %d. Accept or renegotiate the contract.', $page['contract_version'], $cursor['contract_version']);
          $counts['contract_mismatch'] = 1;
          $cursor['mismatch_noted'] = TRUE;
        }
        $this->compare($run_id, $entity_type_id, $bundle, $page['items'], $counts, $messages, $acted, !empty($cursor['backfill']));
        if ($page['next'] === NULL) {
          $cursor['phase'] = 'delete';
        }
        else {
          $cursor['after'] = $page['next'];
        }
      }
      elseif (!$this->deleteMissing($run_id, $entity_type_id, $bundle, $counts, $messages, $acted)) {
        $cursor = ['index' => $cursor['index'] + 1, 'phase' => 'list', 'after' => ''] + $cursor;
      }
    }
    catch (RateLimitedException $e) {
      // The same page is asked for again once the origin allows it.
      $cursor['wait_until'] = $this->time->getCurrentTime() + $e->retryAfter;
      $next = self::WAIT;
    }
    catch (SubscriptionException $e) {
      // Nothing is deleted from a bundle the origin could not list in full.
      $messages[] = sprintf('%s %s: %s', $entity_type_id, $bundle, $e->getMessage());
      $counts['failed_bundles'] = ($counts['failed_bundles'] ?? 0) + 1;
      $cursor = ['index' => $cursor['index'] + 1, 'phase' => 'list', 'after' => ''] + $cursor;
    }
    $this->database->update(self::TABLE)
      ->fields([
        'counts' => json_encode($counts),
        'messages' => json_encode(array_slice($messages, 0, self::MAX_MESSAGES)),
        'cursor' => json_encode(['acted' => $acted] + $cursor),
      ])
      ->condition('id', $run_id)
      ->execute();
    return $next;
  }

  /**
   * Runs a whole reconciliation now, or as far as the origin allows.
   *
   * @return array
   *   The run; see load(). Still running when the origin asked this site to
   *   wait: cron carries on with it.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When it cannot start.
   */
  public function run(string $trigger): array {
    $run_id = $this->start($trigger);
    while ($this->step($run_id) === self::MORE) {
      // Each step does one page.
    }
    return $this->load($run_id) ?? [];
  }

  /**
   * Continues or starts the scheduled run, within a time budget. For cron.
   */
  public function cron(int $budget = 30): void {
    $interval = (int) ($this->receiving->get('reconcile.interval') ?? 86400);
    $run_id = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['id'])
      ->condition('pairing', $this->active->id())
      ->condition('status', 'running')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($run_id === FALSE) {
      $last = (int) $this->state->get($this->active->stateKey('content_relay_replica.reconciled'), 0);
      // A backfill for languages just added does not wait for the interval.
      $backfill = (bool) $this->state->get($this->active->stateKey(LanguageSubscription::BACKFILL), FALSE);
      if (!$backfill && ($interval <= 0 || $this->time->getCurrentTime() - $last < $interval)) {
        return;
      }
      try {
        $run_id = $this->start($backfill ? 'backfill' : 'cron');
      }
      catch (SubscriptionException) {
        return;
      }
      $this->state->delete($this->active->stateKey(LanguageSubscription::BACKFILL));
      $this->state->set($this->active->stateKey('content_relay_replica.reconciled'), $this->time->getCurrentTime());
    }
    $until = microtime(TRUE) + $budget;
    while (microtime(TRUE) < $until && $this->step((int) $run_id) === self::MORE) {
      // Each step does one page.
    }
  }

  /**
   * A run.
   *
   * @return array|null
   *   The row, with counts, messages and cursor decoded.
   */
  public function load(int $run_id): ?array {
    $row = $this->database->select(self::TABLE, 'r')->fields('r')->condition('id', $run_id)->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $row['counts'] = json_decode($row['counts'], TRUE) ?: [];
    $row['messages'] = json_decode($row['messages'], TRUE) ?: [];
    $row['cursor'] = $row['cursor'] === NULL ? NULL : json_decode($row['cursor'], TRUE);
    return $row;
  }

  /**
   * Past runs, newest first.
   *
   * @return array[]
   *   Rows, decoded as load() does.
   */
  public function history(int $limit = 50): array {
    $ids = $this->database->select(self::TABLE, 'r')->fields('r', ['id'])->condition('pairing', $this->active->id())->orderBy('id', 'DESC')->range(0, $limit)->execute()->fetchCol();
    return array_values(array_filter(array_map(fn ($id) => $this->load((int) $id), $ids)));
  }

  /**
   * Fills recorded gaps in the stream from the origin.
   *
   * @param int $budget
   *   Most sequence numbers to ask about in this call.
   *
   * @return array<string, int>
   *   Counts of what was done, as in a run.
   */
  public function fillGaps(int $budget = 1000): array {
    $origin = $this->origin->get();
    if ($origin === NULL) {
      return [];
    }
    $counts = [];
    $messages = [];
    $acted = 0;
    foreach ($this->streams->getGaps($origin['site_id']) as $gap) {
      [$after, $missing] = $gap;
      $last = min($missing, $after + $budget);
      $sequences = range($after + 1, $last);
      $budget -= count($sequences);
      try {
        $latest = [];
        foreach (array_chunk($sequences, 200) as $chunk) {
          $answer = $this->originClient->stream($chunk);
          foreach ($answer['events'] as $event) {
            // The item's current state answers for all its missed events.
            $latest[$event['entity_type'] . ':' . $event['uuid']] = $event;
          }
          // Near its read limit, the origin answers only the first ones.
          if (count($answer['answered']) < count($chunk)) {
            $last = $answer['answered'] === [] ? $chunk[0] - 1 : max($answer['answered']);
            $budget = 0;
            break;
          }
        }
      }
      catch (SubscriptionException $e) {
        $this->logger->warning('Could not fill the gap after sequence @after: @message', [
          '@after' => $after,
          '@message' => $e->getMessage(),
        ]);
        break;
      }
      foreach ($latest as $event) {
        $this->act($event['entity_type'], $event['bundle'], $event['uuid'], $event['current'], $counts, $messages, $acted);
      }
      if ($last > $after) {
        $this->streams->replaceGap($origin['site_id'], $gap, $last < $missing ? [$last, $missing] : NULL);
      }
      $this->logger->notice('Filled the gap in the stream from sequence @from to @to: @counts.', [
        '@from' => $after + 1,
        '@to' => $last,
        '@counts' => json_encode($counts),
      ]);
      if ($budget <= 0) {
        break;
      }
    }
    return $counts;
  }

  /**
   * Compares a page of the origin's hashes with this site, and acts.
   *
   * @param int $run_id
   *   The run.
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $bundle
   *   The bundle.
   * @param array $items
   *   The page; see OriginClient::hashes().
   * @param array $counts
   *   Counts, updated.
   * @param string[] $messages
   *   Problems, added to.
   * @param int $acted
   *   Items the run acted on so far, updated.
   * @param bool $backfill
   *   Whether the run is a backfill.
   */
  protected function compare(int $run_id, string $entity_type_id, string $bundle, array $items, array &$counts, array &$messages, int &$acted, bool $backfill = FALSE): void {
    $contract = $this->contracts->getCurrent();
    $found = [];
    foreach ($items as $item) {
      $found[] = $item['uuid'];
      $counts['checked'] = ($counts['checked'] ?? 0) + 1;
      if ($item['no_languages'] ?? FALSE) {
        // Not sent to this site; what it has stays as it is.
        $counts['no_languages'] = ($counts['no_languages'] ?? 0) + 1;
        continue;
      }
      $state = $this->syncState->get($entity_type_id, $item['uuid']);
      if ($state !== NULL && $state['detached_at'] === NULL && !$item['not_syncable'] && $state['origin_detached_at'] === NULL && !$this->drifted($state, $item, $contract, $bundle)) {
        $counts['in_sync'] = ($counts['in_sync'] ?? 0) + 1;
        continue;
      }
      $this->act($entity_type_id, $bundle, $item['uuid'], $item['not_syncable'] ? 'not_syncable' : 'exists', $counts, $messages, $acted, $run_id, $backfill);
    }
    $this->syncState->markReconciled($entity_type_id, $found, $run_id);
  }

  /**
   * Deletes a batch of items the origin no longer has.
   *
   * @return bool
   *   TRUE if there may be more.
   */
  protected function deleteMissing(int $run_id, string $entity_type_id, string $bundle, array &$counts, array &$messages, int &$acted): bool {
    $uuids = $this->syncState->notReconciled($entity_type_id, $bundle, $run_id, self::PAGE, (string) $this->origin->getSiteId());
    foreach ($uuids as $uuid) {
      $this->act($entity_type_id, $bundle, $uuid, 'gone', $counts, $messages, $acted, $run_id);
    }
    // Whatever was kept (detached here, or failed) is not looked at again.
    $this->syncState->markReconciled($entity_type_id, $uuids, $run_id);
    return count($uuids) === self::PAGE;
  }

  /**
   * Whether an item here differs from the origin's latest hashes.
   *
   * In sync means every translation this site receives hashes the same as
   * the origin's, and this site has every one it should.
   */
  protected function drifted(array $state, array $item, ?SubscriptionContract $contract, string $bundle): bool {
    if (!in_array($state['status'], [SyncStatus::SYNCED, SyncStatus::SCHEMA_DRIFT], TRUE)) {
      // Errors and failures are worth another try; conflicts and items
      // waiting for something are left alone by settle().
      return TRUE;
    }
    // Hashes from another algorithm cannot be compared, which after an
    // upgrade is every item: the revision says whether this site has the
    // origin's current version.
    if ((int) ($item['hash']['algorithm'] ?? 0) !== (int) $state['hash_algorithm']) {
      return (int) ($item['revision'] ?? -1) !== (int) $state['source_revision'];
    }
    // Translations kept from this site, detached here or in a language it
    // does not have are not compared.
    $ignored = array_flip([
      ...($item['withheld'] ?? []),
      ...$state['detached_translations'],
      ...($state['notes']['skipped_languages'] ?? []),
    ]);
    $origin = array_diff_key(is_array($item['hash']['translations'] ?? NULL) ? $item['hash']['translations'] : [], $ignored);
    $local = array_diff_key($state['source_translation_hashes'], $ignored);
    $languages = $contract?->languagesFor($state['entity_type'], $bundle);
    $excluded = $contract?->excludedLanguages() ?? [];
    foreach ($origin as $langcode => $hash) {
      $received = GenericContentEntityNormalizer::wanted((string) $langcode, $languages, $excluded);
      if ($received && (!isset($local[$langcode]) || !hash_equals((string) $local[$langcode], (string) $hash))) {
        return TRUE;
      }
    }
    // A translation the origin no longer has.
    return array_diff_key($local, $origin) !== [];
  }

  /**
   * Brings one item in line with where it stands on the origin now.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $uuid
   *   The UUID.
   * @param string $current
   *   Where it stands: exists, gone, not_syncable or not_in_contract.
   * @param array $counts
   *   Counts, updated.
   * @param string[] $messages
   *   Problems, added to.
   */
  protected function settle(string $entity_type_id, string $bundle, string $uuid, string $current, array &$counts, array &$messages): void {
    $count = function (string $what) use (&$counts): void {
      $counts[$what] = ($counts[$what] ?? 0) + 1;
    };
    $state = $this->syncState->get($entity_type_id, $uuid);
    if ($current === 'not_in_contract') {
      return;
    }
    if ($state === NULL) {
      if ($current !== 'exists') {
        return;
      }
      // Content made here keeps its UUID to itself.
      /** @var \Drupal\Core\Entity\EntityInterface|null $local */
      $local = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
      if ($local !== NULL) {
        $count('skipped_local');
        return;
      }
    }
    elseif ($state['detached_at'] !== NULL && $current !== 'gone') {
      $count('skipped_detached');
      return;
    }
    elseif ($current === 'exists' && in_array($state['status'], self::LEFT_ALONE, TRUE)) {
      $count('skipped_' . $state['status']);
      return;
    }

    try {
      if ($current === 'gone') {
        $result = $this->applier->apply($this->localEvent(Operation::Delete, $entity_type_id, $bundle, $uuid, $state), (string) $this->origin->getSiteId());
        $count(match ($result->result) {
          ApplyResult::DELETED => 'deleted',
          ApplyResult::SKIPPED_DETACHED => 'skipped_detached',
          default => 'failed',
        });
        return;
      }
      if ($current === 'not_syncable') {
        if ($state['origin_detached_at'] === NULL) {
          $this->applier->apply($this->localEvent(Operation::Detach, $entity_type_id, $bundle, $uuid, $state), (string) $this->origin->getSiteId());
          $count('detached_by_origin');
        }
        return;
      }
      if ($state !== NULL && $state['origin_detached_at'] !== NULL) {
        $this->applier->apply($this->localEvent(Operation::Attach, $entity_type_id, $bundle, $uuid, $state), (string) $this->origin->getSiteId());
        $count('attached_by_origin');
      }
      $result = $this->puller->pull($entity_type_id, $uuid);
      $count(match ($result->result) {
        ApplyResult::APPLIED => 'pulled',
        ApplyResult::UNCHANGED => 'in_sync',
        ApplyResult::ACCEPTED => 'pulled_waiting',
        ApplyResult::CONFLICT => 'skipped_conflict',
        ApplyResult::SKIPPED_DETACHED => 'skipped_detached',
        default => 'failed',
      });
      if ($result->httpStatus >= 400 && $result->result !== ApplyResult::CONFLICT) {
        $messages[] = sprintf('%s %s: %s', $entity_type_id, $uuid, $result->message ?? $result->result);
      }
    }
    catch (RateLimitedException $e) {
      throw $e;
    }
    catch (SubscriptionException $e) {
      $count('failed');
      $messages[] = sprintf('%s %s: %s', $entity_type_id, $uuid, $e->getMessage());
    }
  }

  /**
   * Settles an item now, or queues it once the run has acted on enough.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $uuid
   *   The UUID.
   * @param string $current
   *   Where it stands on the origin; see settle().
   * @param array $counts
   *   Counts, updated.
   * @param string[] $messages
   *   Problems, added to.
   * @param int $acted
   *   Items acted on so far, updated.
   * @param int|null $run_id
   *   The run, if any, for the queue worker to report to.
   * @param bool $backfill
   *   Whether the run is a backfill for languages just added.
   */
  protected function act(string $entity_type_id, string $bundle, string $uuid, string $current, array &$counts, array &$messages, int &$acted, ?int $run_id = NULL, bool $backfill = FALSE): void {
    $item = [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'uuid' => $uuid,
      'current' => $current,
      'queued' => $this->time->getCurrentTime(),
      'run' => $run_id,
      'pairing' => $this->active->id(),
    ];
    if ($acted < $this->maxItems()) {
      $acted++;
      try {
        $this->settle($entity_type_id, $bundle, $uuid, $current, $counts, $messages);
        return;
      }
      catch (RateLimitedException) {
        // Queued below, for when the origin allows more.
      }
    }
    $this->queueFactory->get(self::QUEUE)->createItem($item);
    $counts['queued'] = ($counts['queued'] ?? 0) + 1;
    // A backfill shows what is still to come in the report.
    if ($backfill && $current === 'exists') {
      $this->database->update(SyncStateStore::TABLE)
        ->fields(['status' => SyncStatus::PENDING_BACKFILL])
        ->condition('entity_type', $entity_type_id)
        ->condition('uuid', $uuid)
        ->condition('status', SyncStatus::SYNCED)
        ->execute();
    }
  }

  /**
   * Settles an item a run queued. For the queue worker.
   *
   * @throws \Drupal\content_relay_replica\RateLimitedException
   *   When the origin asks this site to wait; the item stays queued.
   */
  public function processQueued(array $item): void {
    $state = $this->syncState->get($item['entity_type'], $item['uuid']);
    // Applied since it was queued: what the run saw is out of date.
    if ($state !== NULL && (int) $state['last_synced'] > (int) $item['queued']) {
      return;
    }
    $counts = [];
    $messages = [];
    $this->settle($item['entity_type'], $item['bundle'], $item['uuid'], $item['current'], $counts, $messages);
    foreach ($messages as $message) {
      $this->logger->warning('Queued reconciliation: @message', ['@message' => $message]);
    }
    if ($item['run'] === NULL || ($run = $this->load((int) $item['run'])) === NULL) {
      return;
    }
    foreach ($counts as $outcome => $number) {
      $run['counts']['queue_' . $outcome] = ($run['counts']['queue_' . $outcome] ?? 0) + $number;
    }
    $this->database->update(self::TABLE)
      ->fields([
        'counts' => json_encode($run['counts']),
        'messages' => json_encode(array_slice([...$run['messages'], ...$messages], 0, self::MAX_MESSAGES)),
      ])
      ->condition('id', (int) $item['run'])
      ->execute();
  }

  /**
   * An event standing for what the origin's signed answer said about an item.
   *
   * Deletes, detaches and attaches found by reconciliation go through the
   * apply service like events, so its rules hold for them too.
   */
  protected function localEvent(Operation $operation, string $entity_type_id, string $bundle, string $uuid, ?array $state): SyncEvent {
    return SyncEvent::fromArray([
      'v' => SyncEvent::SCHEMA_VERSION,
      'event_id' => $this->uuid->generate(),
      'sequence' => 1,
      'previous_sequence' => NULL,
      'operation' => $operation->value,
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'entity_uuid' => $uuid,
      // Never older than what is here, so never refused as stale.
      'source_revision' => (int) ($state['source_revision'] ?? 0),
      'revisionable' => TRUE,
      'source_changed' => NULL,
      'default_langcode' => 'und',
      'langcodes' => [],
      'hash' => NULL,
      'contract_version' => $this->contracts->getCurrent()?->version() ?? 0,
      'created' => $this->time->getCurrentTime(),
      'dependencies' => [],
      'external_references' => [],
      'files' => [],
      'process' => NULL,
      'snapshot' => NULL,
    ]);
  }

  /**
   * Closes a run.
   */
  protected function finish(int $run_id, array $counts, array $messages, string $status): void {
    $this->database->update(self::TABLE)
      ->fields([
        'finished' => $this->time->getCurrentTime(),
        'status' => $status,
        'counts' => json_encode($counts),
        'messages' => json_encode(array_slice($messages, 0, self::MAX_MESSAGES)),
        'cursor' => NULL,
      ])
      ->condition('id', $run_id)
      ->execute();
    $this->logger->notice('Reconciliation run @id finished: @counts.', [
      '@id' => $run_id,
      '@counts' => json_encode($counts),
    ]);
  }

}
