<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists replicas with their state and actions.
 */
final class ReplicaListController extends ControllerBase {

  public function __construct(
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_origin.replica_registry'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The replica list.
   */
  public function list(): array {
    $counts = $this->statusCounts();
    $rows = [];
    foreach ($this->registry->getAll() as $replica) {
      $active = $this->contracts->getActive($replica->id);
      $pending = $this->contracts->getPending($replica->id);
      $replica_counts = $counts[$replica->id] ?? [];
      $failures = ($replica_counts[EventStatus::FAILED] ?? 0) + ($replica_counts[EventStatus::DEAD_LETTER] ?? 0) + ($replica_counts[EventStatus::FAILED_ON_REPLICA] ?? 0);
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<strong>' . htmlspecialchars($replica->label) . '</strong><br><small>' . htmlspecialchars($replica->id) . '</small>']],
          $this->statusLabel($replica, $pending !== NULL),
          $active ? $this->t('v@version', ['@version' => $active->version()]) . ($pending ? ' ' . $this->t('(v@pending pending)', ['@pending' => $pending->version()]) : '') : $this->t('none yet'),
          $active ? $active->encryption() : '',
          $replica->lastSuccess ? $this->dateFormatter->formatTimeDiffSince($replica->lastSuccess) : $this->t('never'),
          ($replica_counts[EventStatus::QUEUED] ?? 0),
          $failures,
          ['data' => ['#type' => 'operations', '#links' => $this->operations($replica)]],
        ],
        'data-replica' => $replica->id,
      ];
    }
    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Replica'),
          $this->t('Status'),
          $this->t('Contract'),
          $this->t('Encryption'),
          $this->t('Last delivery'),
          $this->t('Queued'),
          $this->t('Failures'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No replicas yet. Generate a pairing code and enter it on the replica.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Event log counts by replica and status.
   *
   * @return array<string, array<string, int>>
   *   Counts.
   */
  protected function statusCounts(): array {
    $query = $this->database->select(Outbox::TABLE, 'o')->fields('o', ['replica_id', 'status']);
    $query->addExpression('COUNT(*)', 'total');
    $counts = [];
    foreach ($query->groupBy('replica_id')->groupBy('status')->execute() as $row) {
      $counts[$row->replica_id][$row->status] = (int) $row->total;
    }
    return $counts;
  }

  /**
   * A replica's status in words.
   */
  protected function statusLabel(Replica $replica, bool $contract_pending): string {
    return (string) match ($replica->status) {
      Replica::ACTIVE => $this->t('Active'),
      Replica::SUSPENDED => $this->t('Suspended'),
      Replica::REVOKED => $this->t('Revoked'),
      default => $replica->contractVersion === 0 && $contract_pending
        ? $this->t('Waiting for the replica to acknowledge its contract')
        : $this->t('Waiting for approval'),
    };
  }

  /**
   * Operation links for a replica.
   */
  protected function operations(Replica $replica): array {
    $links = [
      'contract' => [
        'title' => $this->t('Contract'),
        'url' => Url::fromRoute('content_relay_origin.replica_contract', ['replica_id' => $replica->id]),
      ],
      'addresses' => [
        'title' => $this->t('Allowed addresses'),
        'url' => Url::fromRoute('content_relay_origin.replica_addresses', ['replica_id' => $replica->id]),
      ],
    ];
    $actions = match ($replica->status) {
      Replica::PENDING => $replica->approved ? ['revoke'] : ['approve', 'revoke'],
      Replica::ACTIVE => ['suspend', 'revoke'],
      Replica::SUSPENDED => ['resume', 'revoke'],
      default => [],
    };
    $titles = [
      'approve' => $this->t('Approve'),
      'suspend' => $this->t('Suspend'),
      'resume' => $this->t('Resume'),
      'revoke' => $this->t('Revoke'),
    ];
    foreach ($actions as $action) {
      $links[$action] = [
        'title' => $titles[$action],
        'url' => Url::fromRoute('content_relay_origin.replica_action', [
          'replica_id' => $replica->id,
          'action' => $action,
        ]),
      ];
    }
    return $links;
  }

}
