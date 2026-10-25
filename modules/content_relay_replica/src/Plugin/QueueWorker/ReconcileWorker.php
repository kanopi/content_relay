<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\QueueWorker;

use Drupal\content_relay_replica\RateLimitedException;
use Drupal\content_relay_replica\Reconciler;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settles items reconciliation queued, at most reconcile.max_items per run.
 */
#[QueueWorker(
  id: Reconciler::QUEUE,
  title: new TranslatableMarkup('Content Relay reconciliation'),
  cron: ['time' => 30],
)]
final class ReconcileWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Items processed by this worker in this request.
   */
  protected int $processed = 0;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly Reconciler $reconciler,
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
      $container->get('content_relay_replica.reconciler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !is_string($data['entity_type'] ?? NULL) || !is_string($data['uuid'] ?? NULL) || !is_string($data['current'] ?? NULL)) {
      return;
    }
    if ($this->processed >= $this->reconciler->maxItems()) {
      throw new SuspendQueueException('Reached the reconciliation limit for this run.');
    }
    $this->processed++;
    try {
      $this->reconciler->processQueued($data + ['bundle' => '', 'queued' => 0, 'run' => NULL]);
    }
    catch (RateLimitedException $e) {
      // The origin's read limit: the item and the rest wait.
      throw new SuspendQueueException($e->getMessage(), 0, $e);
    }
  }

}
