<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Drush\Commands;

use Drupal\content_relay_replica\ItemDetacher;
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

}
