<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay_origin\BulkPush;
use Drupal\content_relay_origin\Plugin\views\field\BulkPushForm;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\content_relay_origin\Report\ReportViews;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms a bulk push: rows selected in the report, or all it matches.
 *
 * Shows how many items go to each replica and how many are left out because
 * they are not syncable or detached, lets the push be limited to some
 * languages, then records the pushes through the Batch API.
 */
final class BulkPushConfirmForm extends ConfirmFormBase {

  public function __construct(
    protected BulkPush $bulkPush,
    protected ReportViews $reportViews,
    protected ReplicaRegistry $registry,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected LanguageManagerInterface $languageManager,
    protected Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.bulk_push'),
      $container->get('content_relay_origin.report_views'),
      $container->get('content_relay_origin.replica_registry'),
      $container->get('tempstore.private'),
      $container->get('language_manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_bulk_push_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Push these items?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('view.' . ReportViews::DEFAULT_VIEW . '.' . ReportViews::DEFAULT_DISPLAY);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Push');
  }

  /**
   * The report rows to push, from the selection or the filters.
   *
   * @return int[]
   *   Row IDs.
   */
  private function rowIds(): array {
    $request = $this->getRequest();
    if ($request->query->has('selection')) {
      return (array) $this->tempStoreFactory->get(BulkPushForm::TEMPSTORE)->get(BulkPushForm::SELECTION);
    }
    $filters = $request->query->all()['filters'] ?? [];
    return $this->reportViews->rowIds(
      (string) $request->query->get('view', ReportViews::DEFAULT_VIEW),
      (string) $request->query->get('display', ReportViews::DEFAULT_DISPLAY),
      is_array($filters) ? $filters : [],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $ids = $form_state->get('row_ids') ?? $this->rowIds();
    $form_state->set('row_ids', $ids);
    $plan = $this->bulkPush->plan($ids);
    $replicas = $this->registry->getAll();

    $form['summary'] = [
      '#type' => 'table',
      '#header' => [$this->t('Replica'), $this->t('Items to push'), $this->t('Left out')],
      '#empty' => $this->t('Nothing matches.'),
    ];
    $counts = BulkPush::countByReplica($plan['items']);
    foreach (array_unique([...array_keys($counts), ...array_keys($plan['skipped'])]) as $replica_id) {
      $skipped = [];
      foreach ($plan['skipped'][$replica_id] ?? [] as $reason => $count) {
        $skipped[] = $this->t('@count @reason', [
          '@count' => $count,
          '@reason' => ReportIndex::statusLabels()[$reason] ?? $reason,
        ]);
      }
      $form['summary'][$replica_id] = [
        'replica' => ['#plain_text' => isset($replicas[$replica_id]) ? $replicas[$replica_id]->label : $replica_id],
        'count' => ['#plain_text' => (string) ($counts[$replica_id] ?? 0)],
        'skipped' => ['#plain_text' => $skipped === [] ? '-' : implode(', ', array_map('strval', $skipped))],
      ];
    }

    $langcodes = $ids === [] ? [] : $this->database->select(ReportIndex::TABLE, 'r')
      ->fields('r', ['langcode'])
      ->condition('id', $ids, 'IN')
      ->distinct()
      ->execute()
      ->fetchCol();
    if (count($langcodes) > 1) {
      $options = [];
      foreach ($langcodes as $langcode) {
        $options[$langcode] = $this->languageManager->getLanguage($langcode)?->getName() ?? $langcode;
      }
      $form['langcodes'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Languages'),
        '#description' => $this->t('Push only items with a selected translation among the rows.'),
        '#options' => $options,
        '#default_value' => array_keys($options),
      ];
    }
    $form = parent::buildForm($form, $form_state);
    if ($plan['items'] === []) {
      $form['actions']['submit']['#disabled'] = TRUE;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $langcodes = $form_state->hasValue('langcodes') ? array_values(array_filter((array) $form_state->getValue('langcodes'))) : NULL;
    $plan = $this->bulkPush->plan((array) $form_state->get('row_ids'), $langcodes);
    $this->tempStoreFactory->get(BulkPushForm::TEMPSTORE)->delete(BulkPushForm::SELECTION);
    if ($plan['items'] === []) {
      $this->messenger()->addWarning($this->t('Nothing to push.'));
    }
    else {
      batch_set($this->bulkPush->batch($plan['items']));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
