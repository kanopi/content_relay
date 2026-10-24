<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event;

/**
 * How a replica handles an event when it arrives.
 */
enum ProcessingMode: string {

  // Apply during the request and answer 200.
  case Immediate = 'immediate';
  // Store, answer 202, apply from a queue worker.
  case Queued = 'queued';
  // Immediate unless the event has files to fetch or the site is busy.
  case Auto = 'auto';

}
