<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\QueueWorker;

use Drupal\content_relay_origin\DeliveryScheduler;
use Drupal\content_relay_origin\DeliveryService;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends due events to one replica.
 */
#[QueueWorker(
  id: DeliveryScheduler::QUEUE,
  title: new TranslatableMarkup('Content Relay delivery'),
  cron: ['time' => 60],
)]
final class DeliveryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly DeliveryService $delivery,
    protected readonly DeliveryScheduler $scheduler,
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
      $container->get('content_relay_origin.delivery'),
      $container->get('content_relay_origin.delivery_scheduler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $replica = is_array($data) ? (string) ($data['replica'] ?? '') : '';
    if ($replica !== '' && $this->delivery->deliver($replica)) {
      $this->scheduler->scheduleDue();
    }
  }

}
