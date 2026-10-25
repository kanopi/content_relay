<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\QueueWorker;

use Drupal\content_relay_replica\File\FileSync;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Fetches the binary of one synced file.
 *
 * Failures are recorded on the file and retried by cron with backoff, not by
 * the queue, so a failing download does not block the ones behind it.
 */
#[QueueWorker(
  id: FileSync::QUEUE,
  title: new TranslatableMarkup('Content Relay file downloads'),
  cron: ['time' => 120],
)]
final class FileDownloadWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly FileSync $files,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('content_relay_replica.file_sync'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $uuid = is_array($data) ? (string) ($data['uuid'] ?? '') : '';
    $record = $uuid === '' ? NULL : $this->files->get($uuid);
    if ($record !== NULL && $record['status'] === FileSync::PENDING) {
      $this->files->process($uuid);
    }
  }

}
