<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Uuid\Uuid;
use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay_origin\BulkPush;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\HashBackfill;
use Drupal\content_relay_origin\ItemSyncControl;
use Drupal\content_relay_origin\ItemValidator;
use Drupal\content_relay_origin\ManualPush;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\OriginReconciler;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\PairingCodes;
use Drupal\content_relay_origin\ReplayService;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the Content Relay origin.
 *
 * Each command exits non-zero when what it was asked to do did not happen.
 */
final class OriginCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'content_relay_origin.manual_push')]
    private readonly ManualPush $push,
    #[Autowire(service: 'content_relay_origin.bulk_push')]
    private readonly BulkPush $bulkPush,
    #[Autowire(service: 'content_relay_origin.item_sync_control')]
    private readonly ItemSyncControl $control,
    #[Autowire(service: 'content_relay_origin.report')]
    private readonly ReportIndex $report,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity.repository')]
    private readonly EntityRepositoryInterface $entityRepository,
    #[Autowire(service: 'content_relay_origin.hash_backfill')]
    private readonly HashBackfill $backfill,
    #[Autowire(service: 'content_relay_origin.reconciler')]
    private readonly OriginReconciler $reconciler,
    #[Autowire(service: 'content_relay_origin.replay')]
    private readonly ReplayService $replay,
    #[Autowire(service: 'content_relay_origin.replica_registry')]
    private readonly ReplicaRegistry $registry,
    #[Autowire(service: 'content_relay_origin.pairing_codes')]
    private readonly PairingCodes $pairingCodes,
    #[Autowire(service: 'content_relay_origin.outbox')]
    private readonly Outbox $outbox,
    #[Autowire(service: 'content_relay_origin.contract_manager')]
    private readonly ContractManager $contracts,
    #[Autowire(service: 'content_relay_origin.item_validator')]
    private readonly ItemValidator $validator,
  ) {
    parent::__construct();
  }

  /**
   * Pushes one item to replicas now.
   */
  #[CLI\Command(name: 'content-relay:push', aliases: ['cr-push'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'id', description: 'Entity ID or UUID.')]
  #[CLI\Option(name: 'replica', description: 'Replica IDs, comma-separated. Defaults to every replica that receives the bundle.')]
  #[CLI\Option(name: 'force', description: 'Send even to replicas that already have the current revision.')]
  #[CLI\Option(name: 'mode', description: 'Processing on the replica: immediate, queued or auto.')]
  #[CLI\Usage(name: 'drush content-relay:push node 42 --force', description: 'Send node 42 to every replica that receives it.')]
  public function push(
    string $entity_type,
    string $id,
    array $options = [
      'replica' => self::REQ,
      'force' => FALSE,
      'mode' => 'immediate',
    ],
  ): int {
    $entity = $this->load($entity_type, $id);
    $mode = ProcessingMode::tryFrom((string) $options['mode']) ?? throw new \InvalidArgumentException('Mode must be immediate, queued or auto.');
    $results = $this->push->push($entity, $this->list($options['replica']), (bool) $options['force'], $mode);
    $labels = ManualPush::outcomeLabels();
    $rows = [];
    $failed = $results === [];
    foreach ($results as $replica_id => $result) {
      $rows[] = [
        'replica' => $replica_id,
        'outcome' => (string) ($labels[$result['status']] ?? $result['status']),
        'event' => $result['row'] ?? '',
      ];
      $failed = $failed || !in_array($result['status'], [
        EventStatus::APPLIED,
        EventStatus::ACCEPTED,
        ManualPush::IN_SYNC,
        ManualPush::QUEUED,
      ], TRUE);
    }
    $this->io()->table([dt('Replica'), dt('Outcome'), dt('Event')], $rows);
    if ($failed) {
      $this->logger()->error(dt('The push did not reach every replica.'));
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Pushes every item matching filters, through the outgoing queue.
   */
  #[CLI\Command(name: 'content-relay:bulk-push', aliases: ['cr-bulk-push'])]
  #[CLI\Option(name: 'entity-type', description: 'Entity type ID.')]
  #[CLI\Option(name: 'bundle', description: 'Bundle.')]
  #[CLI\Option(name: 'status', description: 'Report statuses, comma-separated, such as out_of_sync,never_sent.')]
  #[CLI\Option(name: 'replica', description: 'Replica ID.')]
  #[CLI\Option(name: 'langcode', description: 'Language code.')]
  #[CLI\Option(name: 'any-out-of-sync', description: 'Items with any translation out of sync.')]
  #[CLI\Option(name: 'dry-run', description: 'Show the counts without pushing.')]
  #[CLI\Usage(name: 'drush content-relay:bulk-push --bundle=article --status=out_of_sync --replica=abc', description: 'Push every out-of-sync article to one replica.')]
  public function bulkPush(
    array $options = [
      'entity-type' => self::REQ,
      'bundle' => self::REQ,
      'status' => self::REQ,
      'replica' => self::REQ,
      'langcode' => self::REQ,
      'any-out-of-sync' => FALSE,
      'dry-run' => FALSE,
    ],
  ): void {
    $ids = $this->report->rowIds([
      'entity_type' => $options['entity-type'],
      'bundle' => $options['bundle'],
      'status' => $this->list($options['status']),
      'replica_id' => $options['replica'],
      'langcode' => $options['langcode'],
      'any_out_of_sync' => $options['any-out-of-sync'],
    ]);
    $plan = $this->bulkPush->plan($ids);
    foreach (BulkPush::countByReplica($plan['items']) as $replica_id => $count) {
      $this->io()->writeln(dt('@replica: @count to push', ['@replica' => $replica_id, '@count' => $count]));
    }
    foreach ($plan['skipped'] as $replica_id => $reasons) {
      foreach ($reasons as $reason => $count) {
        $this->io()->writeln(dt('@replica: @count left out (@reason)', [
          '@replica' => $replica_id,
          '@count' => $count,
          '@reason' => $reason,
        ]));
      }
    }
    if ($options['dry-run'] || $plan['items'] === []) {
      return;
    }
    $results = [];
    foreach (array_chunk($plan['items'], BulkPush::BATCH_SIZE) as $chunk) {
      foreach ($this->bulkPush->queueMultiple($chunk) as $replica_id => $counts) {
        foreach ($counts as $outcome => $count) {
          $results[$replica_id][$outcome] = ($results[$replica_id][$outcome] ?? 0) + $count;
        }
      }
    }
    foreach (BulkPush::summarize($results) as $line) {
      $this->logger()->success((string) $line);
    }
  }

  /**
   * Marks an item not syncable.
   */
  #[CLI\Command(name: 'content-relay:not-syncable', aliases: ['cr-not-syncable'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'id', description: 'Entity ID or UUID.')]
  #[CLI\Option(name: 'replica', description: 'Only for these replicas, comma-separated.')]
  #[CLI\Option(name: 'behavior', description: 'For replicas that have it: detach or remove. Defaults to the configured behavior.')]
  #[CLI\Option(name: 'langcode', description: 'Only this translation. Replicas keep what they have of it.')]
  public function notSyncable(
    string $entity_type,
    string $id,
    array $options = [
      'replica' => self::REQ,
      'behavior' => self::REQ,
      'langcode' => self::REQ,
    ],
  ): void {
    if ($options['langcode']) {
      $resent = $this->control->setTranslationSyncable($this->load($entity_type, $id), $options['langcode'], FALSE, $this->list($options['replica']));
      $this->logger()->success(dt('The @langcode translation is marked not syncable. @count replica(s) told.', [
        '@langcode' => $options['langcode'],
        '@count' => count($resent),
      ]));
      return;
    }
    $behavior = $options['behavior'] ?: NULL;
    if ($behavior !== NULL && !in_array($behavior, [NotSyncableFlags::DETACH, NotSyncableFlags::REMOVE], TRUE)) {
      throw new \InvalidArgumentException('Behavior must be detach or remove.');
    }
    $told = $this->control->markNotSyncable($this->load($entity_type, $id), $this->list($options['replica']), $behavior);
    $this->logger()->success(dt('Marked not syncable. @count replica(s) told.', ['@count' => count($told)]));
  }

  /**
   * Marks an item syncable again.
   */
  #[CLI\Command(name: 'content-relay:syncable', aliases: ['cr-syncable'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'id', description: 'Entity ID or UUID.')]
  #[CLI\Option(name: 'replica', description: 'Only remove the flags for these replicas, comma-separated.')]
  #[CLI\Option(name: 'langcode', description: 'Only this translation.')]
  public function syncable(
    string $entity_type,
    string $id,
    array $options = [
      'replica' => self::REQ,
      'langcode' => self::REQ,
    ],
  ): void {
    if ($options['langcode']) {
      $resent = $this->control->setTranslationSyncable($this->load($entity_type, $id), $options['langcode'], TRUE, $this->list($options['replica']));
      $this->logger()->success(dt('The @langcode translation is syncable again. Sending to @count replica(s).', [
        '@langcode' => $options['langcode'],
        '@count' => count($resent),
      ]));
      return;
    }
    $resumed = $this->control->markSyncable($this->load($entity_type, $id), $this->list($options['replica']));
    $this->logger()->success(dt('Marked syncable. Sending to @count replica(s) again.', ['@count' => count($resumed)]));
  }

  /**
   * Loads an item by ID or UUID.
   */
  private function load(string $entity_type_id, string $id): ContentEntityInterface {
    $entity = Uuid::isValid($id)
      ? $this->entityRepository->loadEntityByUuid($entity_type_id, $id)
      : $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException(sprintf('There is no %s with ID or UUID %s.', $entity_type_id, $id));
    }
    return $entity;
  }

  /**
   * A comma-separated option as a list, or NULL when empty.
   */
  private function list(mixed $value): ?array {
    $list = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    return $list === [] ? NULL : $list;
  }

  /**
   * Computes hashes for content saved before this origin kept them.
   */
  #[CLI\Command(name: 'content-relay:backfill-hashes', aliases: ['cr-backfill'])]
  #[CLI\Option(name: 'all', description: 'Recompute every item, not only those without a hash for their current version.')]
  public function backfillHashes(array $options = ['all' => FALSE]): int {
    $count = $this->backfill->run((bool) $options['all'], function (string $entity_type_id, string $bundle, int $done): void {
      $this->logger()->info(dt('@type @bundle: @done hashed so far.', [
        '@type' => $entity_type_id,
        '@bundle' => $bundle,
        '@done' => $done,
      ]));
    });
    $this->logger()->success(dt('@count item(s) hashed.', ['@count' => $count]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Re-sends what replicas are missing, according to the status report.
   *
   * At most reconcile.max_items are recorded for sending now; the rest are
   * queued and sent by cron at the same rate.
   */
  #[CLI\Command(name: 'content-relay:reconcile', aliases: ['cr-reconcile'])]
  #[CLI\Option(name: 'replica', description: 'One replica ID. Defaults to every replica.')]
  public function reconcile(array $options = ['replica' => NULL]): int {
    $result = $this->reconciler->run('drush', $options['replica'] ?: NULL);
    if ($result['busy'] > 0) {
      $this->logger()->error(dt('@count item(s) from the last run are still queued; try again once cron has sent them.', ['@count' => $result['busy']]));
      return self::EXIT_FAILURE;
    }
    foreach ($result['sent'] as $replica_id => $outcomes) {
      $this->logger()->notice(dt('@replica: @outcomes.', [
        '@replica' => $replica_id,
        '@outcomes' => json_encode($outcomes),
      ]));
    }
    $this->logger()->success(dt('@queued more item(s) queued for cron.', ['@queued' => $result['queued']]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Sends a failed or dead-lettered event to its replica again.
   */
  #[CLI\Command(name: 'content-relay:replay', aliases: ['cr-replay'])]
  #[CLI\Argument(name: 'entry', description: 'The event log entry number.')]
  public function replay(int $entry): int {
    try {
      $new = $this->replay->replay($entry);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Replayed as event log entry @id.', ['@id' => $new]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Lists the replicas paired with this origin.
   */
  #[CLI\Command(name: 'content-relay:replicas', aliases: ['cr-replicas'])]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'status' => 'Status',
    'contract' => 'Contract',
    'endpoint' => 'Endpoint',
    'last_success' => 'Last success',
  ])]
  public function replicas(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->registry->getAll() as $replica) {
      $rows[$replica->id] = [
        'id' => $replica->id,
        'label' => $replica->label,
        'status' => $replica->status . ($replica->approved ? '' : ' (not approved)'),
        'contract' => $this->contracts->getActive($replica->id)?->version() ?? '-',
        'endpoint' => $replica->endpoint,
        'last_success' => $replica->lastSuccess === NULL ? '-' : date('Y-m-d H:i:s', $replica->lastSuccess),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Generates a pairing code for a replica to pair with.
   */
  #[CLI\Command(name: 'content-relay:pairing-code', aliases: ['cr-pairing-code'])]
  #[CLI\Option(name: 'bundles', description: 'Limit what the replica may receive, as entity_type:bundle pairs, comma-separated.')]
  public function pairingCode(array $options = ['bundles' => self::REQ]): int {
    $allowed = NULL;
    foreach ($this->list($options['bundles']) ?? [] as $pair) {
      [$entity_type_id, $bundle] = explode(':', $pair, 2) + [1 => ''];
      if ($bundle === '') {
        $this->logger()->error(dt('Bundles are entity_type:bundle pairs.'));
        return self::EXIT_FAILURE;
      }
      $allowed[$entity_type_id][] = $bundle;
    }
    $code = $this->pairingCodes->generate($allowed, 0);
    $this->output()->writeln($code['code']);
    $this->logger()->notice(dt('Valid until @time. It is shown only now.', ['@time' => date('Y-m-d H:i:s', $code['expires'])]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Shows each replica's delivery state.
   */
  #[CLI\Command(name: 'content-relay:status', aliases: ['cr-status'])]
  public function status(): int {
    $replicas = $this->registry->getAll();
    if ($replicas === []) {
      $this->logger()->warning(dt('No replicas are paired.'));
      return self::EXIT_SUCCESS;
    }
    $problems = FALSE;
    foreach ($replicas as $replica) {
      $counts = $this->outbox->countsByStatus($replica->id);
      $problems = $problems || ($counts[EventStatus::DEAD_LETTER] ?? 0) > 0 || ($counts[EventStatus::FAILED_ON_REPLICA] ?? 0) > 0;
      $this->output()->writeln(dt('@label (@id): @status, contract @contract, last success @success', [
        '@label' => $replica->label,
        '@id' => $replica->id,
        '@status' => $replica->status,
        '@contract' => $this->contracts->getActive($replica->id)?->version() ?? '-',
        '@success' => $replica->lastSuccess === NULL ? '-' : date('Y-m-d H:i:s', $replica->lastSuccess),
      ]));
      foreach ($counts as $status => $count) {
        $this->output()->writeln(sprintf('  %-20s %d', $status, $count));
      }
    }
    return $problems ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Checks an item's hashes here and on every replica that gets it.
   */
  #[CLI\Command(name: 'content-relay:validate', aliases: ['cr-validate'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'id', description: 'Entity ID or UUID.')]
  public function validate(string $entity_type, string $id): int {
    $result = $this->validator->validate($this->load($entity_type, $id));
    if ($result['origin_stale']) {
      $this->logger()->warning(dt('The stored hash here was out of date and has been recomputed.'));
    }
    $labels = ItemValidator::resultLabels();
    $failed = FALSE;
    foreach ($result['replicas'] as $replica_id => $row) {
      $failed = $failed || !in_array($row['result'], [
        ItemValidator::IN_SYNC,
        ItemValidator::NOT_SYNCABLE,
        ItemValidator::NO_LANGUAGES,
        ItemValidator::DETACHED,
      ], TRUE);
      $this->output()->writeln(sprintf('%s (%s): %s%s', $row['label'], $replica_id, $labels[$row['result']], $row['differing'] === [] ? '' : ' [' . implode(', ', $row['differing']) . ']'));
    }
    return $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

}
