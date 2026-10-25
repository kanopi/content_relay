<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Delivery states of an event for one replica.
 */
final class EventStatus {

  // Waiting for its first attempt.
  public const QUEUED = 'queued';
  // An attempt failed in a way worth retrying; waiting for next_attempt.
  public const FAILED = 'failed';
  // The replica saved it (200).
  public const APPLIED = 'applied';
  // The replica stored it to apply later (202). Confirmed through the replica
  // status endpoint.
  public const ACCEPTED = 'accepted';
  // The replica already had this or a newer revision, or refused it as a
  // conflict (409). Done; never retried.
  public const STALE = 'stale';
  // The replica received a valid event and could not save it (422). The
  // replica owns retries; the origin does not resend.
  public const FAILED_ON_REPLICA = 'failed_on_replica';
  // Refused permanently, or out of attempts. Kept until replayed.
  public const DEAD_LETTER = 'dead_letter';
  // Never sent, because a later event carried the same change or the entity
  // is gone. Not a failure.
  public const SUPERSEDED = 'superseded';
  // Replaced by a replay, which has its own row.
  public const REPLAYED = 'replayed';
  // Not sent because the contract in force does not include its bundle.
  public const SKIPPED = 'skipped';

  /**
   * States waiting to be sent.
   */
  public const PENDING = [self::QUEUED, self::FAILED];

  /**
   * Final states that may be pruned after the retention period.
   */
  public const PRUNABLE = [self::APPLIED, self::STALE, self::SUPERSEDED, self::REPLAYED, self::SKIPPED];

  /**
   * States a replay can start from.
   */
  public const REPLAYABLE = [self::DEAD_LETTER, self::FAILED_ON_REPLICA];

  /**
   * Labels, by state.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels.
   */
  public static function labels(): array {
    return [
      self::QUEUED => new TranslatableMarkup('Queued'),
      self::FAILED => new TranslatableMarkup('Failed, will retry'),
      self::APPLIED => new TranslatableMarkup('Applied'),
      self::ACCEPTED => new TranslatableMarkup('Accepted (queued on replica)'),
      self::STALE => new TranslatableMarkup('Stale or conflict'),
      self::FAILED_ON_REPLICA => new TranslatableMarkup('Failed on replica'),
      self::DEAD_LETTER => new TranslatableMarkup('Dead-lettered'),
      self::SUPERSEDED => new TranslatableMarkup('Superseded'),
      self::REPLAYED => new TranslatableMarkup('Replayed'),
      self::SKIPPED => new TranslatableMarkup('Skipped'),
    ];
  }

}
