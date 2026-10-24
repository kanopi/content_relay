<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

/**
 * States of a synced item on the replica.
 */
final class SyncStatus {

  public const SYNCED = 'synced';
  public const CONFLICT = 'conflict';
  // Saved, but the copy does not hash the same as the origin's.
  public const ERROR = 'error';
  // The last valid event for it could not be saved.
  public const APPLY_FAILED = 'apply_failed';
  public const WAITING_FILES = 'waiting_files';
  public const WAITING_DEPENDENCY = 'waiting_dependency';
  public const DETACHED_BY_ORIGIN = 'detached_by_origin';
  public const DETACHED_BY_REPLICA = 'detached_by_replica';
  public const SCHEMA_DRIFT = 'schema_drift';

}
