<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\QueueWorker;

use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retries a failed event from its stored copy, without contacting the origin.
 */
#[QueueWorker(
  id: FailedEventStore::RETRY_QUEUE,
  title: new TranslatableMarkup('Content Relay retries'),
  cron: ['time' => 60],
)]
final class RetryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly SyncApplier $applier,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('content_relay_replica.applier'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $result = $this->applier->retry((int) ($data['failure'] ?? 0));
    }
    catch (\InvalidArgumentException) {
      // Resolved, superseded or dismissed since it was queued.
      return;
    }
    if ($result->result === ApplyResult::BUSY) {
      throw new RequeueException();
    }
  }

}
