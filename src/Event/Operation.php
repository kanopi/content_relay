<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event;

/**
 * What a sync event asks the replica to do.
 */
enum Operation: string {

  case Create = 'create';
  case Update = 'update';
  case Delete = 'delete';
  // The origin stopped sending an item; the replica keeps its copy.
  case Detach = 'detach';
  // The origin resumed sending an item; a full push follows.
  case Attach = 'attach';

  /**
   * Whether events of this kind carry a snapshot and its hashes.
   */
  public function carriesSnapshot(): bool {
    return $this === self::Create || $this === self::Update;
  }

}
