<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Drush\Commands;

use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\Puller;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\Validator;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Schema\SchemaWatcher;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_relay_replica\ItemDetacher;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\content_relay_replica\SyncStateStore;
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
  ) {
    parent::__construct();
  }

  /**
   * Detaches a synced item: origin updates are no longer applied to it.
   */
  #[CLI\Command(name: 'content-relay:detach', aliases: ['cr-detach'])]
  #[CLI\Argument(name: 'entity_type', description: 'Entity type ID.')]
  #[CLI\Argument(name: 'uuid', description: 'UUID of the synced item.')]
  public function detach(string $entity_type, string $uuid): int {
    if ($this->syncState->get($entity_type, $uuid) === NULL) {
      $this->logger()->error(dt('@type @uuid was not received from the origin.', [
        '@type' => $entity_type,
        '@uuid' => $uuid,
      ]));
      return self::EXIT_FAILURE;
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
  public function reattach(string $entity_type, string $uuid, array $options = ['accept-origin' => FALSE]): int {
    $state = $this->syncState->get($entity_type, $uuid);
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
  #[CLI\Command(name: 'content-relay:reconcile', aliases: ['cr-reconcile'])]
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
  #[CLI\Command(name: 'content-relay:validate', aliases: ['cr-validate'])]
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

}
