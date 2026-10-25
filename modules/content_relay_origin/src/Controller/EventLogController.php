<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One event log entry, in full.
 */
final class EventLogController extends ControllerBase {

  public function __construct(
    private readonly Outbox $outbox,
    private readonly ReplicaRegistry $registry,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.outbox'),
      $container->get('content_relay_origin.replica_registry'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The page.
   */
  public function view(int $row_id): array {
    $row = $this->outbox->load($row_id) ?? throw new NotFoundHttpException();
    $date = fn ($value) => $value === NULL ? '-' : $this->dateFormatter->format((int) $value, 'long');
    $user = $row['triggered_by'] === NULL ? NULL : $this->entityTypeManager()->getStorage('user')->load($row['triggered_by']);
    $items = [
      [$this->t('Event ID'), $row['event_id']],
      [$this->t('Replica'), ($this->registry->get($row['replica_id'])->label ?? '') . ' (' . $row['replica_id'] . ')'],
      [$this->t('Sequence'), $row['sequence']],
      [$this->t('Operation'), $row['operation']],
      [$this->t('Item'), sprintf('%s %s %s', $row['entity_type'], $row['bundle'], $row['entity_uuid'])],
      [$this->t('Revision'), $row['source_revision'] ?? '-'],
      [$this->t('Status'), EventStatus::labels()[$row['status']] ?? $row['status']],
      [$this->t('Result'), $row['result_code'] ?? '-'],
      [$this->t('Attempts'), $row['attempts']],
      [$this->t('Last HTTP status'), $row['last_http_status'] ?? '-'],
      [$this->t('Reason'), $row['reason'] ?? $this->t('saved on this site')],
      [$this->t('Triggered by'), $user?->label() ?? '-'],
      [$this->t('Replay of entry'), $row['replay_of'] ?? '-'],
      [$this->t('Recorded'), $date($row['created'])],
      [$this->t('First sent'), $date($row['first_sent'])],
      [
        $this->t('Next attempt'),
        in_array($row['status'], EventStatus::PENDING, TRUE) ? $date($row['next_attempt']) : '-',
      ],
      [$this->t('Completed'), $date($row['completed'])],
    ];
    $rows = [];
    foreach ($items as [$label, $value]) {
      $rows[] = [['data' => $label, 'header' => TRUE], (string) $value];
    }
    $build['#title'] = $this->t('Event log entry @id', ['@id' => $row_id]);
    $build['details'] = ['#type' => 'table', '#rows' => $rows];
    $build['response'] = [
      '#type' => 'details',
      '#title' => $this->t('Last response from the replica'),
      '#open' => $row['last_response'] !== NULL,
      'body' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        'text' => ['#plain_text' => (string) ($row['last_response'] ?? $this->t('None'))],
      ],
    ];
    $replay = Url::fromRoute('content_relay_origin.event_replay', ['row_id' => $row_id]);
    if (in_array($row['status'], EventStatus::REPLAYABLE, TRUE) && $replay->access()) {
      $build['replay'] = [
        '#type' => 'link',
        '#title' => $this->t('Replay'),
        '#url' => $replay,
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $build;
  }

}
