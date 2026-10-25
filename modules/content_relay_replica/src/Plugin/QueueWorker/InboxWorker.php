<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\QueueWorker;

use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\Processing\ReceivedEvents;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Applies an event accepted for queued processing.
 *
 * Goes through the same apply service as the webhook, so its answer is
 * recorded the same way and the origin reads it from the status endpoint.
 * An item another process is writing is put back to try again.
 */
#[QueueWorker(
  id: ReceivedEvents::QUEUE,
  title: new TranslatableMarkup('Content Relay queued events'),
  cron: ['time' => 120],
)]
final class InboxWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ReceivedEvents $received,
    protected readonly SyncApplier $applier,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('content_relay_replica.received_events'),
      $container->get('content_relay_replica.applier'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $event_id = is_array($data) ? (string) ($data['event_id'] ?? '') : '';
    $waiting = $event_id === '' ? NULL : $this->received->get($event_id);
    if ($waiting === NULL) {
      // Applied already, by an earlier copy of this item.
      return;
    }
    $result = $this->applier->apply($waiting['event'], $waiting['source_site']);
    if ($result->result === ApplyResult::BUSY) {
      throw new RequeueException(sprintf('Event %s is waiting for its item to be free.', $event_id));
    }
    $this->received->remove($event_id);
  }

}
