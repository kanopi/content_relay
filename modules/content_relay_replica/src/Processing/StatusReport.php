<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Processing;

use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\ProcessedEvents;
use Drupal\content_relay_replica\StreamTracker;

/**
 * What became of events, for the origin's status requests.
 *
 * Each event is one of:
 * - final: applied, refused or failed for good; with the answer it got, as
 *   it would have been given immediately;
 * - pending: accepted and not yet settled (waiting in the queue, retrying a
 *   transient failure, or waiting for a dependency);
 * - unknown: never received, or forgotten after the retention period.
 */
class StatusReport {

  public function __construct(
    protected ProcessedEvents $processed,
    protected ReceivedEvents $received,
    protected ContractStore $contracts,
    protected StreamTracker $stream,
  ) {}

  /**
   * Statuses of events.
   *
   * @param string[] $event_ids
   *   Event IDs.
   * @param string $source_site
   *   The origin asking.
   *
   * @return array{events: array<string, array>, contract_version: int|null, last_sequence: int}
   *   By event ID, its state and, when final, its answer; and this site's
   *   contract version and last sequence received.
   */
  public function report(array $event_ids, string $source_site): array {
    $events = [];
    foreach ($event_ids as $event_id) {
      $record = $this->processed->get($event_id);
      if ($record !== NULL && $record['http_status'] !== 202) {
        $events[$event_id] = ['state' => 'final', 'http_status' => $record['http_status']] + $record['result'];
      }
      elseif ($record !== NULL || $this->received->has($event_id)) {
        $events[$event_id] = ['state' => 'pending'];
      }
      else {
        $events[$event_id] = ['state' => 'unknown'];
      }
    }
    return [
      'events' => $events,
      'contract_version' => $this->contracts->getCurrent()?->version(),
      'last_sequence' => $this->stream->getLastSequence($source_site),
    ];
  }

}
