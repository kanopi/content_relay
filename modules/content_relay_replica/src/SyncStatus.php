<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Core\StringTranslation\TranslatableMarkup;

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
  // Its translations in a language just added are still to be pulled.
  public const PENDING_BACKFILL = 'pending_backfill';

  /**
   * Labels, by status.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels.
   */
  public static function labels(): array {
    return [
      self::SYNCED => new TranslatableMarkup('Synced'),
      self::CONFLICT => new TranslatableMarkup('Conflict'),
      self::ERROR => new TranslatableMarkup('Error'),
      self::APPLY_FAILED => new TranslatableMarkup('Apply failed'),
      self::WAITING_FILES => new TranslatableMarkup('Waiting for files'),
      self::WAITING_DEPENDENCY => new TranslatableMarkup('Waiting for a dependency'),
      self::DETACHED_BY_ORIGIN => new TranslatableMarkup('Detached by origin'),
      self::DETACHED_BY_REPLICA => new TranslatableMarkup('Detached by replica'),
      self::SCHEMA_DRIFT => new TranslatableMarkup('Schema drift'),
      self::PENDING_BACKFILL => new TranslatableMarkup('Pending backfill'),
    ];
  }

}
