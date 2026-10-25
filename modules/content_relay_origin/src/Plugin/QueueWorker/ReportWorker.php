<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\QueueWorker;

use Drupal\content_relay_origin\Report\ReportRebuild;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rebuilds the status report one chunk of a bundle at a time.
 */
#[QueueWorker(
  id: ReportRebuild::QUEUE,
  title: new TranslatableMarkup('Content Relay status report'),
  cron: ['time' => 30],
)]
final class ReportWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ReportRebuild $rebuild,
    protected readonly QueueFactory $queueFactory,
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
      $container->get('content_relay_origin.report_rebuild'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !is_string($data['entity_type'] ?? NULL) || !is_string($data['bundle'] ?? NULL)) {
      return;
    }
    $next = $this->rebuild->rebuildChunk($data['entity_type'], $data['bundle'], $data['after'] ?? NULL);
    if ($next !== NULL) {
      $this->queueFactory->get(ReportRebuild::QUEUE)->createItem(['after' => $next] + $data);
    }
  }

}
