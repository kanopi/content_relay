<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Controller;

use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\StreamTracker;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the origins this site receives from, with what can be done to each.
 */
final class OriginListController extends ControllerBase {

  public function __construct(
    protected ActivePairing $active,
    protected OriginPeer $origin,
    protected ContractStore $contracts,
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_replica.active_pairing'),
      $container->get('content_relay_replica.origin'),
      $container->get('content_relay_replica.contracts'),
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The origin list.
   */
  public function list(): array {
    $items = $this->database->select(SyncStateStore::TABLE, 's')
      ->fields('s', ['source_site'])
      ->groupBy('source_site');
    $items->addExpression('COUNT(*)', 'items');
    $counts = $items->execute()->fetchAllKeyed();
    $last = $this->database->select(StreamTracker::TABLE, 't')->fields('t', ['source_site', 'changed'])->execute()->fetchAllKeyed();

    $rows = [];
    foreach ($this->active->ids() as $pairing) {
      $origin = $this->origin->get($pairing);
      $contract = $this->active->run($pairing, fn () => $this->contracts->getCurrent());
      $site = $origin['site_id'] ?? '';
      $label = $this->entityTypeManager()->getStorage('content_relay_origin_pairing')->load($pairing)?->label() ?? $origin['label'] ?? $pairing;
      $rows[] = [
        ['data' => ['#markup' => '<strong>' . htmlspecialchars((string) $label) . '</strong><br><small>' . htmlspecialchars($pairing) . '</small>']],
        $origin === NULL ? $this->t('Not paired') : $origin['url'],
        $contract ? $this->t('v@version', ['@version' => $contract->version()]) : $this->t('none yet'),
        (int) ($counts[$site] ?? 0),
        isset($last[$site]) && (int) $last[$site] > 0 ? $this->dateFormatter->formatTimeDiffSince((int) $last[$site]) : $this->t('never'),
        ['data' => ['#type' => 'operations', '#links' => $this->operations($pairing)]],
      ];
    }
    return [
      'origins' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Origin'),
          $this->t('Address'),
          $this->t('Contract'),
          $this->t('Items'),
          $this->t('Last event'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('This site does not receive from any origin yet. <a href=":url">Pair it with one</a>.', [
          ':url' => Url::fromRoute('content_relay_replica.pair')->toString(),
        ]),
      ],
      'pair' => [
        '#type' => 'link',
        '#title' => $rows === [] ? $this->t('Pair with an origin') : $this->t('Pair with another origin'),
        '#url' => Url::fromRoute('content_relay_replica.pair'),
        '#attributes' => ['class' => ['button']],
        '#access' => $this->currentUser()->hasPermission('administer content relay replica'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Title for a pairing's pages: the origin's label.
   */
  public function title(ContentRelayOriginPairing $content_relay_origin_pairing): string {
    return (string) $content_relay_origin_pairing->label();
  }

  /**
   * Links for one pairing the current user may follow.
   */
  protected function operations(string $pairing): array {
    $links = [];
    foreach ([
      'content_relay_replica.subscription' => $this->t('Contract'),
      'content_relay_replica.pairing_settings' => $this->t('Settings'),
      'content_relay_replica.pairing_reconcile' => $this->t('Reconciliation'),
      'content_relay_replica.unpair' => $this->t('Unpair'),
    ] as $route => $title) {
      $url = Url::fromRoute($route, ['content_relay_origin_pairing' => $pairing]);
      if ($url->access()) {
        $links[$route] = ['title' => $title, 'url' => $url];
      }
    }
    return $links;
  }

}
