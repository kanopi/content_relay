<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Items that failed to save here, with retries one at a time or in bulk.
 */
final class FailuresForm extends FormBase {

  /**
   * Rows shown at once.
   */
  protected const LIMIT = 200;

  public function __construct(
    protected FailedEventStore $failures,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.failed_events'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_failures';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->getRequest()->query;
    $filters = [
      'status' => (string) ($query->get('status') ?: FailedEventStore::OPEN),
      'entity_type' => (string) $query->get('entity_type', ''),
      'bundle' => (string) $query->get('bundle', ''),
      'failure_class' => (string) $query->get('failure_class', ''),
    ];
    $form_state->set('filters', $filters);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t("Valid events from the origin that could not be saved here. Fix the cause (a field setting, a missing language, a mapping), then retry: the stored event is applied again without asking the origin. Pull fresh applies the origin's current version instead.") . '</p>',
    ];
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline', 'clearfix']],
    ];
    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        FailedEventStore::OPEN => $this->t('Open'),
        FailedEventStore::DISMISSED => $this->t('Dismissed'),
        FailedEventStore::RESOLVED => $this->t('Resolved'),
        FailedEventStore::SUPERSEDED => $this->t('Superseded'),
      ],
      '#default_value' => $filters['status'],
    ];
    $form['filters']['entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity type'),
      '#size' => 16,
      '#default_value' => $filters['entity_type'],
    ];
    $form['filters']['bundle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle'),
      '#size' => 16,
      '#default_value' => $filters['bundle'],
    ];
    $form['filters']['failure_class'] = [
      '#type' => 'select',
      '#title' => $this->t('Kind'),
      '#options' => [
        '' => $this->t('- Any -'),
        FailedEventStore::PERMANENT => $this->t('Permanent'),
        FailedEventStore::TRANSIENT => $this->t('Temporary'),
        FailedEventStore::DEPENDENCY => $this->t('Waiting for a dependency'),
      ],
      '#default_value' => $filters['failure_class'],
    ];
    $form['filters']['filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#submit' => ['::filter'],
      '#limit_validation_errors' => [],
    ];

    $options = [];
    foreach ($this->failures->find($filters, self::LIMIT) as $row) {
      $options[$row['id']] = [
        'item' => [
          'data' => [
            '#type' => 'link',
            '#title' => $row['label'] ?: $row['uuid'],
            '#url' => Url::fromRoute('content_relay_replica.failure', ['failure_id' => $row['id']]),
          ],
        ],
        'type' => $row['entity_type'] . ' ' . $row['bundle'],
        'operation' => $row['operation'],
        'error' => $row['error_code'] . ': ' . mb_strimwidth((string) $row['message'], 0, 120, '…'),
        'attempts' => $row['attempts'],
        'last' => $this->dateFormatter->format((int) $row['last_failed'], 'short'),
      ];
    }
    $form['failures'] = [
      '#type' => 'tableselect',
      '#header' => [
        'item' => $this->t('Item'),
        'type' => $this->t('Type'),
        'operation' => $this->t('Operation'),
        'error' => $this->t('Error'),
        'attempts' => $this->t('Attempts'),
        'last' => $this->t('Last attempt'),
      ],
      '#options' => $options,
      '#empty' => $this->t('No failures match.'),
    ];
    $may_retry = $this->currentUser()->hasPermission('pull content relay items') && $filters['status'] === FailedEventStore::OPEN;
    $form['actions'] = ['#type' => 'actions', '#access' => $may_retry && $options !== []];
    $form['actions']['retry'] = ['#type' => 'submit', '#value' => $this->t('Retry selected'), '#name' => 'retry'];
    $form['actions']['retry_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Retry all matching the filters'),
      '#name' => 'retry_all',
    ];
    return $form;
  }

  /**
   * Applies the filters by reloading with them in the query.
   */
  public function filter(array &$form, FormStateInterface $form_state): void {
    $query = array_filter([
      'status' => $form_state->getUserInput()['status'] ?? NULL,
      'entity_type' => $form_state->getUserInput()['entity_type'] ?? NULL,
      'bundle' => $form_state->getUserInput()['bundle'] ?? NULL,
      'failure_class' => $form_state->getUserInput()['failure_class'] ?? NULL,
    ]);
    $form_state->setRedirect('content_relay_replica.failures', [], ['query' => $query]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ids = ($form_state->getTriggeringElement()['#name'] ?? '') === 'retry_all'
      ? array_map(fn (array $row) => (int) $row['id'], $this->failures->find($form_state->get('filters')))
      : array_map('intval', array_keys(array_filter((array) $form_state->getValue('failures'))));
    if ($ids === []) {
      $this->messenger()->addWarning($this->t('Nothing selected.'));
      return;
    }
    $this->logger('content_relay_replica')->notice('@user retried @count failed item(s).', [
      '@user' => $this->currentUser()->getAccountName(),
      '@count' => count($ids),
    ]);
    $operations = [];
    foreach (array_chunk($ids, 20) as $chunk) {
      $operations[] = [[self::class, 'batchStep'], [$chunk]];
    }
    batch_set([
      'title' => $this->t('Retrying failed items'),
      'operations' => $operations,
      'finished' => [self::class, 'batchFinished'],
    ]);
  }

  /**
   * Batch operation: retries a chunk of failures.
   */
  public static function batchStep(array $ids, &$context): void {
    $applier = \Drupal::service('content_relay_replica.applier');
    foreach ($ids as $id) {
      try {
        $result = $applier->retry((int) $id);
        $outcome = in_array($result->result, [ApplyResult::APPLIED, ApplyResult::UNCHANGED, ApplyResult::DELETED], TRUE) ? 'fixed' : 'failed';
      }
      catch (\InvalidArgumentException | \RuntimeException) {
        $outcome = 'skipped';
      }
      $context['results'][$outcome] = ($context['results'][$outcome] ?? 0) + 1;
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results): void {
    \Drupal::messenger()->addStatus(new TranslatableMarkup('@fixed applied, @failed still failing, @skipped no longer open.', [
      '@fixed' => $results['fixed'] ?? 0,
      '@failed' => $results['failed'] ?? 0,
      '@skipped' => $results['skipped'] ?? 0,
    ]));
    if (!$success) {
      \Drupal::messenger()->addError(new TranslatableMarkup('The retries did not all finish.'));
    }
  }

}
