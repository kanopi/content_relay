<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\QueueWorker;

use Drupal\content_relay_origin\OriginReconciler;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-sends items reconciliation queued, at most reconcile.max_items per run.
 */
#[QueueWorker(
  id: OriginReconciler::QUEUE,
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
    protected readonly OriginReconciler $reconciler,
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
      $container->get('content_relay_origin.reconciler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !is_string($data['entity_type'] ?? NULL) || !is_string($data['uuid'] ?? NULL) || !is_string($data['replica_id'] ?? NULL)) {
      return;
    }
    if ($this->processed >= $this->reconciler->maxItems()) {
      // The rest waits for the next cron run.
      throw new SuspendQueueException('Reached the reconciliation limit for this run.');
    }
    $this->processed++;
    $this->reconciler->processQueued($data);
  }

}
