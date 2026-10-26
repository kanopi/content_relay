<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\QueueWorker;

use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Releases translations of languages this site no longer receives.
 */
#[QueueWorker(
  id: LanguageSubscription::QUEUE,
  title: new TranslatableMarkup('Content Relay languages'),
  cron: ['time' => 30],
)]
final class LanguageWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly LanguageSubscription $languages,
    protected readonly QueueFactory $queueFactory,
    protected readonly ActivePairing $active,
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
      $container->get('content_relay_replica.language_subscription'),
      $container->get('queue'),
      $container->get('content_relay_replica.active_pairing'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !is_string($data['entity_type'] ?? NULL) || !is_string($data['bundle'] ?? NULL)) {
      return;
    }
    // Items queued before pairings had names are the first pairing's.
    $data += ['pairing' => ContentRelayOriginPairing::DEFAULT];
    $next = $this->active->run($data['pairing'], fn () => $this->languages->processQueued($data + [
      'langcodes' => NULL,
      'wanted' => NULL,
      'mode' => 'keep',
      'after' => '',
    ]));
    if ($next !== NULL) {
      $this->queueFactory->get(LanguageSubscription::QUEUE)->createItem($next);
    }
  }

}
