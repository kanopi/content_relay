<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Processing;

use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay_replica\ContractStore;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Decides whether an event is applied now or from the queue.
 *
 * The mode is the event's own override if it has one (a manual push can ask
 * for "immediate"), else the one the contract it names gives its bundle, else
 * immediate. Auto applies now unless the event brings a file to fetch, the
 * item is being written by another process, or the site is busy: too many
 * events already waiting, or (when configured) a high load average.
 */
class ProcessingPolicy {

  public function __construct(
    protected ContractStore $contracts,
    protected ReceivedEvents $received,
    protected LockBackendInterface $lock,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The mode an event is handled in: Immediate or Queued.
   */
  public function decide(SyncEvent $event): ProcessingMode {
    $mode = $event->process ?? $this->configured($event);
    if ($mode !== ProcessingMode::Auto) {
      return $mode;
    }
    return $this->shouldQueue($event) ? ProcessingMode::Queued : ProcessingMode::Immediate;
  }

  /**
   * The mode the contract gives the event's bundle.
   */
  protected function configured(SyncEvent $event): ProcessingMode {
    $contract = $this->contracts->accepts($event->contractVersion) ?? $this->contracts->getCurrent();
    return ProcessingMode::tryFrom($contract?->processingFor($event->entityType, $event->bundle) ?? '') ?? ProcessingMode::Immediate;
  }

  /**
   * Whether auto mode should queue an event.
   */
  protected function shouldQueue(SyncEvent $event): bool {
    if ($event->files !== []) {
      return TRUE;
    }
    if (!$this->lock->lockMayBeAvailable('content_relay_replica:apply:' . $event->entityType . ':' . $event->entityUuid)) {
      return TRUE;
    }
    $settings = $this->configFactory->get('content_relay_replica.settings');
    $backlog = (int) $settings->get('processing.auto_backlog');
    if ($backlog > 0 && $this->received->backlog() >= $backlog) {
      return TRUE;
    }
    $max_load = (float) $settings->get('processing.auto_max_load');
    if ($max_load > 0 && function_exists('sys_getloadavg')) {
      $load = sys_getloadavg();
      return $load !== FALSE && $load[0] >= $max_load;
    }
    return FALSE;
  }

}
