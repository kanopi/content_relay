<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\content_relay_replica\Adopter;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\ItemDetacher;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\Puller;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\Schema\SchemaWatcher;
use Drupal\content_relay_replica\StreamTracker;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SubscriptionManager;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\Validator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for a Content Relay replica.
 *
 * Each command exits non-zero when what it was asked to do did not happen.
 */
final class ReplicaCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'content_relay_replica.detacher')]
    private readonly ItemDetacher $detacher,
    #[Autowire(service: 'content_relay_replica.sync_state')]
    private readonly SyncStateStore $syncState,
    #[Autowire(service: 'content_relay_replica.file_sync')]
    private readonly FileSync $files,
    #[Autowire(service: 'entity.repository')]
    private readonly EntityRepositoryInterface $entityRepository,
    #[Autowire(service: 'content_relay_replica.schema_watcher')]
    private readonly SchemaWatcher $schemaWatcher,
    #[Autowire(service: 'content_relay_replica.puller')]
    private readonly Puller $puller,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'content_relay_replica.reconciler')]
    private readonly Reconciler $reconciler,
    #[Autowire(service: 'content_relay_replica.validator')]
    private readonly Validator $validator,
    #[Autowire(service: 'content_relay_replica.language_subscription')]
    private readonly LanguageSubscription $languages,
    #[Autowire(service: 'content_relay_replica.subscription')]
    private readonly SubscriptionManager $subscription,
    #[Autowire(service: 'content_relay_replica.origin')]
    private readonly OriginPeer $origin,
    #[Autowire(service: 'content_relay_replica.contracts')]
    private readonly ContractStore $contracts,
    #[Autowire(service: 'content_relay_replica.stream')]
    private readonly StreamTracker $streams,
    #[Autowire(service: 'content_relay_replica.failed_events')]
    private readonly FailedEventStore $failures,
    #[Autowire(service: 'content_relay_replica.applier')]
    private readonly SyncApplier $applier,
    #[Autowire(service: 'content_relay_replica.adopter')]
    private readonly Adopter $adopter,
  ) {
    parent::__construct();
  }

  /**
   * Detaches a synced item: origin updates are no longer applied to it.
   */
  #[CLI\Command(name: 'content-relay:detach', aliases: ['cr-detach'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the synced item.')]
  #[CLI\Option(name: 'langcode', description: 'Only this translation, by the origin\'s langcode.')]
  public function detach(string $entity_type, string $uuid, array $options = ['langcode' => self::REQ]): int {
    if ($this->syncState->get($entity_type, $uuid) === NULL) {
      $this->logger()->error(dt('@type @uuid was not received from the origin.', [
        '@type' => $entity_type,
        '@uuid' => $uuid,
      ]));
      return self::EXIT_FAILURE;
    }
    if ($options['langcode']) {
      $done = $this->detacher->detachTranslation($entity_type, $uuid, $options['langcode']);
      $this->logger()->success($done ? dt('The @langcode translation is detached.', ['@langcode' => $options['langcode']]) : dt('Already detached.'));
      return self::EXIT_SUCCESS;
    }
    if (!$this->detacher->detach($entity_type, $uuid)) {
      $this->logger()->warning(dt('Already detached.'));
      return self::EXIT_SUCCESS;
    }
    $this->logger()->success(dt('Detached.'));
    return self::EXIT_SUCCESS;
  }

  /**
   * Reattaches a detached item.
   */
  #[CLI\Command(name: 'content-relay:reattach', aliases: ['cr-reattach'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the synced item.')]
  #[CLI\Option(name: 'accept-origin', description: 'If the item was changed here, replace the changes with the origin\'s version. Without it, a changed item stays detached.')]
  #[CLI\Option(name: 'langcode', description: 'Only this translation, by the origin\'s langcode.')]
  public function reattach(
    string $entity_type,
    string $uuid,
    array $options = [
      'accept-origin' => FALSE,
      'langcode' => self::REQ,
    ],
  ): int {
    $state = $this->syncState->get($entity_type, $uuid);
    if ($options['langcode']) {
      if (!$this->detacher->reattachTranslation($entity_type, $uuid, $options['langcode'], (bool) $options['accept-origin'])) {
        $this->logger()->error(dt('The @langcode translation is not detached, or was changed here: use --accept-origin to replace the changes.', ['@langcode' => $options['langcode']]));
        return self::EXIT_FAILURE;
      }
      $this->logger()->success(dt('The @langcode translation is reattached.', ['@langcode' => $options['langcode']]));
      return self::EXIT_SUCCESS;
    }
    if ($state === NULL || $state['detached_at'] === NULL) {
      $this->logger()->error(dt('@type @uuid is not detached on this site.', ['@type' => $entity_type, '@uuid' => $uuid]));
      return self::EXIT_FAILURE;
    }
    $edits = $this->detacher->localEdits($entity_type, $uuid);
    if (!$this->detacher->reattach($entity_type, $uuid, (bool) $options['accept-origin'])) {
      $this->logger()->error(dt('The item was changed here (@langcodes). Use --accept-origin to replace the changes; it stays detached.', ['@langcodes' => implode(', ', $edits ?? [])]));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Reattached. The origin will send its current version.'));
    return self::EXIT_SUCCESS;
  }

  /**
   * Fetches a synced file from the origin again.
   */
  #[CLI\Command(name: 'content-relay:pull-file', aliases: ['cr-pull-file'])]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the file.')]
  public function pullFile(string $uuid): int {
    $status = $this->files->pull($uuid);
    if ($status !== FileSync::DONE) {
      $record = $this->files->get($uuid);
      $this->logger()->error(dt('The file could not be fetched: @error', ['@error' => $record['last_error'] ?? 'it was not received from the origin']));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Fetched.'));
    return self::EXIT_SUCCESS;
  }

  /**
   * Fetches missing or altered files: of one item, or of every synced file.
   */
  #[CLI\Command(name: 'content-relay:pull-missing-files', aliases: ['cr-pull-missing'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID of a synced item.')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the item.')]
  #[CLI\Option(name: 'all', description: 'Check every synced file instead.')]
  #[CLI\Usage(name: 'drush content-relay:pull-missing-files --all', description: 'Fetch every synced file that is missing or altered.')]
  public function pullMissingFiles(?string $entity_type = NULL, ?string $uuid = NULL, array $options = ['all' => FALSE]): int {
    if ($options['all']) {
      $uuids = $this->files->verifyAll();
    }
    else {
      /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
      $entity = $entity_type === NULL || $uuid === NULL ? NULL : $this->entityRepository->loadEntityByUuid($entity_type, $uuid);
      if (!$entity instanceof ContentEntityInterface || $this->syncState->get($entity_type, $uuid) === NULL) {
        $this->logger()->error(dt('Give a synced item (entity type and UUID), or --all.'));
        return self::EXIT_FAILURE;
      }
      $uuids = $this->files->queueMissingFor($entity);
    }
    $failed = 0;
    foreach ($uuids as $file_uuid) {
      $failed += (int) ($this->files->pull($file_uuid) !== FileSync::DONE);
    }
    $this->logger()->notice(dt('@count file(s) missing or altered; @fetched fetched.', [
      '@count' => count($uuids),
      '@fetched' => count($uuids) - $failed,
    ]));
    return $failed === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Checks every received bundle against the origin's schema.
   */
  #[CLI\Command(name: 'content-relay:check-schema', aliases: ['cr-check-schema'])]
  public function checkSchema(): int {
    try {
      $results = $this->schemaWatcher->check();
    }
    catch (SubscriptionException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $failed = FALSE;
    foreach ($results as $bundle => $result) {
      $this->io()->writeln(sprintf('%s (%s)%s', $bundle, $result['mode'], $result['changed'] ? ', changed since the last check' : ''));
      foreach ($result['problems'] as $problem) {
        $this->io()->writeln('  problem: ' . $problem);
        $failed = TRUE;
      }
      foreach ($result['warnings'] as $warning) {
        $this->io()->writeln('  warning: ' . $warning);
      }
    }
    return $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Pulls every synced item of a mapping's origin bundle and applies it again.
   */
  #[CLI\Command(name: 'content-relay:reapply-mapping', aliases: ['cr-reapply-mapping'])]
  #[CLI\Argument(name: 'mapping', description: 'Mapping ID, such as node__article.')]
  public function reapplyMapping(string $mapping): int {
    /** @var \Drupal\content_relay_replica\Entity\ContentRelayMapping|null $entity */
    $entity = $this->entityTypeManager->getStorage('content_relay_mapping')->load($mapping);
    if ($entity === NULL) {
      $this->logger()->error(dt('There is no mapping @mapping.', ['@mapping' => $mapping]));
      return self::EXIT_FAILURE;
    }
    $uuids = $this->database->select(SyncStateStore::TABLE, 's')
      ->fields('s', ['uuid'])
      ->condition('entity_type', $entity->getSourceEntityType())
      ->condition('bundle', $entity->getSourceBundle())
      ->isNull('detached_at')
      ->execute()
      ->fetchCol();
    $failed = 0;
    foreach ($uuids as $uuid) {
      try {
        $failed += (int) ($this->puller->pull($entity->getSourceEntityType(), $uuid, TRUE)->httpStatus !== 200);
      }
      catch (SubscriptionException $e) {
        $this->logger()->warning(dt('@uuid: @message', ['@uuid' => $uuid, '@message' => $e->getMessage()]));
        $failed++;
      }
    }
    $this->logger()->notice(dt('@count item(s) re-applied, @failed failed.', [
      '@count' => count($uuids) - $failed,
      '@failed' => $failed,
    ]));
    return $failed === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Pulls an item's latest version from the origin.
   *
   * An item edited here is left as it is unless --accept-origin is given.
   */
  #[CLI\Command(name: 'content-relay:pull', aliases: ['cr-pull'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID on the origin.')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the item.')]
  #[CLI\Option(name: 'accept-origin', description: 'Replace edits made here with the origin\'s version.')]
  public function pull(string $entity_type, string $uuid, array $options = ['accept-origin' => FALSE]): int {
    try {
      $result = $this->puller->pull($entity_type, $uuid, FALSE, (bool) $options['accept-origin']);
    }
    catch (SubscriptionException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $message = dt('@result@code: @message', [
      '@result' => $result->result,
      '@code' => $result->code === NULL ? '' : ' (' . $result->code . ')',
      '@message' => (string) ($result->message ?? 'done'),
    ]);
    if ($result->result === ApplyResult::CONFLICT) {
      $this->logger()->error($message . ' ' . dt('Use --accept-origin to replace the edits.'));
      return self::EXIT_FAILURE;
    }
    if ($result->httpStatus >= 400) {
      $this->logger()->error($message);
      return self::EXIT_FAILURE;
    }
    $this->logger()->success($message);
    return self::EXIT_SUCCESS;
  }

  /**
   * Compares this site with the origin, pulling and deleting to match.
   */
  #[CLI\Command(name: 'content-relay:replica-reconcile', aliases: ['cr-replica-reconcile'])]
  public function reconcile(): int {
    try {
      $run = $this->reconciler->run('drush');
    }
    catch (SubscriptionException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    foreach ($run['messages'] as $message) {
      $this->logger()->warning($message);
    }
    if ($run['status'] === 'running') {
      $this->logger()->warning(dt('The origin asked this site to wait before reading more; cron carries on with run @id.', ['@id' => $run['id']]));
    }
    $counts = [];
    foreach ($run['counts'] as $outcome => $number) {
      $counts[] = $outcome . ': ' . $number;
    }
    $this->logger()->notice(dt('Run @id: @counts.', [
      '@id' => $run['id'],
      '@counts' => $counts === [] ? dt('nothing to check') : implode(', ', $counts),
    ]));
    $failed = ($run['counts']['failed'] ?? 0) + ($run['counts']['failed_bundles'] ?? 0) + ($run['counts']['contract_mismatch'] ?? 0);
    return $failed === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Takes over content this site already has, for a replica made from a copy.
   *
   * Items identical to the origin's are marked in sync; items that differ
   * become conflicts to review. Safe to run again: adopted items are skipped.
   */
  #[CLI\Command(name: 'content-relay:adopt', aliases: ['cr-adopt'])]
  public function adopt(): int {
    try {
      $counts = $this->adopter->run(function (string $entity_type_id, string $bundle, array $counts): void {
        $this->logger()->info(dt('@type @bundle: @counts', [
          '@type' => $entity_type_id,
          '@bundle' => $bundle,
          '@counts' => json_encode($counts),
        ]));
      });
    }
    catch (SubscriptionException $e) {
      $this->logger()->error(dt('@message Run the command again to carry on.', ['@message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }
    ksort($counts);
    $lines = [];
    foreach ($counts as $outcome => $number) {
      $lines[] = $outcome . ': ' . $number;
    }
    $this->logger()->success(dt('Adopted existing content. @counts.', ['@counts' => $lines === [] ? dt('Nothing to adopt') : implode(', ', $lines)]));
    if (($counts['adopted_edited'] ?? 0) > 0) {
      $this->logger()->warning(dt('@count item(s) differ from the origin and are listed as conflicts in the status report.', ['@count' => $counts['adopted_edited']]));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Fills gaps in the event stream from the origin.
   */
  #[CLI\Command(name: 'content-relay:fill-gaps', aliases: ['cr-fill-gaps'])]
  public function fillGaps(): int {
    $counts = $this->reconciler->fillGaps();
    $this->logger()->notice(dt('Gaps filled: @counts.', ['@counts' => $counts === [] ? dt('none') : json_encode($counts)]));
    return ($counts['failed'] ?? 0) === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Recomputes an item's hashes here and on the origin, and compares them.
   */
  #[CLI\Command(name: 'content-relay:replica-validate', aliases: ['cr-replica-validate'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID on the origin.')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the synced item.')]
  public function validate(string $entity_type, string $uuid): int {
    try {
      $result = $this->validator->validate($entity_type, $uuid);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $result['local']
      ? $this->logger()->success(dt('Here: the copy hashes as it should.'))
      : $this->logger()->error(dt('Here: the copy does not hash as it should; it was changed here.'));
    if ($result['origin'] === NULL) {
      $this->logger()->error(dt('Origin: @error', ['@error' => $result['origin_error']]));
    }
    elseif ($result['origin']) {
      $this->logger()->success(dt('Origin: in sync at revision @revision.', ['@revision' => $result['origin_revision']]));
    }
    else {
      $this->logger()->error(dt('Origin: out of sync. The origin has revision @revision, this site received @received; differing translations: @langcodes.', [
        '@revision' => $result['origin_revision'],
        '@received' => $result['received_revision'],
        '@langcodes' => implode(', ', $result['differing_translations']) ?: '-',
      ]));
    }
    return $result['local'] && $result['origin'] === TRUE ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Lists languages the origin added, or adds or ignores one.
   */
  #[CLI\Command(name: 'content-relay:languages', aliases: ['cr-languages'])]
  #[CLI\Option(name: 'add', description: 'Start receiving this pending language.')]
  #[CLI\Option(name: 'ignore', description: 'Ignore this pending language for good.')]
  public function languages(array $options = ['add' => self::REQ, 'ignore' => self::REQ]): int {
    $langcode = (string) ($options['add'] ?: $options['ignore']);
    if ($langcode === '') {
      $pending = $this->languages->pending();
      $this->logger()->notice($pending === [] ? dt('No languages wait for a decision.') : dt('Waiting: @languages.', ['@languages' => implode(', ', array_map(fn ($code, $name) => "$name ($code)", array_keys($pending), $pending))]));
      return self::EXIT_SUCCESS;
    }
    $terms = $this->languages->decide($this->subscription->getReplicaTerms(), $langcode, (bool) $options['add']);
    $contract = $this->subscription->propose($terms);
    if ($contract === NULL) {
      $this->logger()->warning(dt('The origin could not be reached; the change will be retried.'));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Contract version @version confirmed.', ['@version' => $contract->version()]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Moves synced translations to another langcode, after a mapping change.
   */
  #[CLI\Command(name: 'content-relay:move-language', aliases: ['cr-move-language'])]
  #[CLI\Argument(name: 'from', description: 'The langcode translations have here now.')]
  #[CLI\Argument(name: 'to', description: 'The langcode they move to, as the new mapping says.')]
  public function moveLanguage(string $from, string $to): int {
    $this->languages->move($from, $to);
    $this->logger()->success(dt('Queued; cron moves them a chunk at a time.'));
    return self::EXIT_SUCCESS;
  }

  /**
   * Pairs this site with an origin, using a code generated there.
   */
  #[CLI\Command(name: 'content-relay:pair', aliases: ['cr-pair'])]
  #[CLI\Argument(name: 'origin_url', description: 'The origin\'s base URL, HTTPS.')]
  #[CLI\Argument(name: 'code', description: 'The pairing code.')]
  #[CLI\Option(name: 'bundles', description: 'What to receive, as entity_type:bundle pairs, comma-separated.')]
  public function pair(string $origin_url, string $code, array $options = ['bundles' => self::REQ]): int {
    $bundles = [];
    foreach (array_filter(array_map('trim', explode(',', (string) $options['bundles']))) as $pair) {
      [$entity_type_id, $bundle] = explode(':', $pair, 2) + [1 => ''];
      if ($bundle === '') {
        $this->logger()->error(dt('Bundles are entity_type:bundle pairs.'));
        return self::EXIT_FAILURE;
      }
      $bundles[$entity_type_id][] = $bundle;
    }
    try {
      $result = $this->subscription->pair($origin_url, $code, ['bundles' => $bundles]);
    }
    catch (SubscriptionException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Paired. Contract version @version@approval.', [
      '@version' => $result['contract']->version(),
      '@approval' => $result['approval_required'] ? dt('; the origin holds events until an administrator there approves this site') : '',
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Shows how this replica stands with its origin.
   */
  #[CLI\Command(name: 'content-relay:replica-status', aliases: ['cr-replica-status'])]
  public function status(): int {
    $origin = $this->origin->get();
    if ($origin === NULL) {
      $this->logger()->warning(dt('This site is not paired with an origin.'));
      return self::EXIT_FAILURE;
    }
    $counts = $this->database->select(SyncStateStore::TABLE, 's')->fields('s', ['status']);
    $counts->addExpression('COUNT(*)', 'total');
    $by_status = $counts->groupBy('s.status')->execute()->fetchAllKeyed();
    ksort($by_status);
    $gaps = $this->streams->getGaps($origin['site_id']);
    $open = $this->failures->countOpen();
    $this->output()->writeln(dt('Origin: @label (@url)', ['@label' => $origin['label'], '@url' => $origin['url']]));
    $this->output()->writeln(dt('Contract: @version', ['@version' => $this->contracts->getCurrent()?->version() ?? '-']));
    $this->output()->writeln(dt('Last event: @sequence; gaps: @gaps', [
      '@sequence' => $this->streams->getLastSequence($origin['site_id']),
      '@gaps' => $gaps === [] ? dt('none') : implode(', ', array_map(fn (array $gap) => ($gap[0] + 1) . '-' . $gap[1], $gaps)),
    ]));
    $this->output()->writeln(dt('Open save failures: @count', ['@count' => $open]));
    $this->output()->writeln(dt('Pending languages: @languages', ['@languages' => implode(', ', array_keys($this->languages->pending())) ?: dt('none')]));
    foreach ($by_status as $status => $total) {
      $this->output()->writeln(sprintf('  %-22s %d', $status, $total));
    }
    return $open > 0 || $gaps !== [] ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Lists items that failed to save here.
   */
  #[CLI\Command(name: 'content-relay:failures', aliases: ['cr-failures'])]
  #[CLI\Option(name: 'status', description: 'open (default), dismissed, resolved or superseded.')]
  #[CLI\Option(name: 'bundle', description: 'Only this bundle.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'item' => 'Item',
    'operation' => 'Operation',
    'error' => 'Error',
    'attempts' => 'Attempts',
  ])]
  public function failures(array $options = ['status' => 'open', 'bundle' => self::REQ, 'format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->failures->find([
      'status' => $options['status'] ?: FailedEventStore::OPEN,
      'bundle' => (string) $options['bundle'],
    ]) as $row) {
      $rows[$row['id']] = [
        'id' => $row['id'],
        'item' => sprintf('%s %s %s', $row['entity_type'], $row['bundle'], $row['label'] ?: $row['uuid']),
        'operation' => $row['operation'],
        'error' => $row['error_code'] . ': ' . mb_strimwidth((string) $row['message'], 0, 80, '…'),
        'attempts' => $row['attempts'],
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Applies stored failed events again, without asking the origin.
   */
  #[CLI\Command(name: 'content-relay:retry', aliases: ['cr-retry'])]
  #[CLI\Argument(name: 'ids', description: 'Failure IDs, comma-separated. Leave out with --all.')]
  #[CLI\Option(name: 'all', description: 'Every open failure, or those of --bundle.')]
  #[CLI\Option(name: 'bundle', description: 'With --all: only this bundle.')]
  public function retry(?string $ids = NULL, array $options = ['all' => FALSE, 'bundle' => self::REQ]): int {
    $list = $options['all']
      ? array_map(fn (array $row) => (int) $row['id'], $this->failures->find(['bundle' => (string) $options['bundle']]))
      : array_map('intval', array_filter(array_map('trim', explode(',', (string) $ids))));
    if ($list === []) {
      $this->logger()->warning(dt('Nothing to retry.'));
      return self::EXIT_SUCCESS;
    }
    $failed = 0;
    foreach ($list as $id) {
      try {
        $result = $this->applier->retry($id);
        $ok = $result->httpStatus < 400 && $result->result !== ApplyResult::ACCEPTED;
      }
      catch (\InvalidArgumentException | \RuntimeException $e) {
        $this->logger()->warning(dt('@id: @message', ['@id' => $id, '@message' => $e->getMessage()]));
        $ok = FALSE;
      }
      $failed += (int) !$ok;
    }
    $this->logger()->notice(dt('@done applied, @failed still failing.', [
      '@done' => count($list) - $failed,
      '@failed' => $failed,
    ]));
    return $failed === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Dismisses a failure: someone decided to leave it; it stays out of sync.
   */
  #[CLI\Command(name: 'content-relay:dismiss', aliases: ['cr-dismiss'])]
  #[CLI\Argument(name: 'id', description: 'The failure ID.')]
  #[CLI\Option(name: 'reason', description: 'Why. Required.')]
  public function dismiss(int $id, array $options = ['reason' => self::REQ]): int {
    $failure = $this->failures->load($id);
    if ($failure === NULL || $failure['status'] !== FailedEventStore::OPEN || trim((string) $options['reason']) === '') {
      $this->logger()->error(dt('Give an open failure ID and --reason.'));
      return self::EXIT_FAILURE;
    }
    $this->failures->dismiss($id, 0, trim((string) $options['reason']));
    $this->logger()->success(dt('Dismissed.'));
    return self::EXIT_SUCCESS;
  }

}
