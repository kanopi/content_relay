<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reconciliation run history, and a button to run one now.
 */
final class ReconcileForm extends FormBase {

  public function __construct(
    protected Reconciler $reconciler,
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.reconciler'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_reconcile';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Reconciliation compares every item this site receives with the origin, pulls what differs or is missing, and deletes what the origin no longer has. Content created on this site, items detached here and items in conflict are left alone.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['run'] = ['#type' => 'submit', '#value' => $this->t('Run reconciliation now')];

    $rows = [];
    foreach ($this->reconciler->history() as $run) {
      $counts = [];
      foreach ($run['counts'] as $outcome => $number) {
        $counts[] = $outcome . ': ' . $number;
      }
      $account = $run['uid'] === NULL ? NULL : $this->entityTypeManager->getStorage('user')->load($run['uid']);
      $rows[] = [
        $run['id'],
        $this->dateFormatter->format((int) $run['started'], 'short'),
        $run['finished'] === NULL ? '' : $this->dateFormatter->format((int) $run['finished'], 'short'),
        $run['trigger'] . ($account ? ' (' . $account->label() . ')' : ''),
        $run['status'],
        ['data' => ['#theme' => 'item_list', '#items' => $counts]],
        ['data' => ['#theme' => 'item_list', '#items' => $run['messages']]],
      ];
    }
    $form['history'] = [
      '#type' => 'table',
      '#caption' => $this->t('Runs'),
      '#header' => [
        $this->t('Run'),
        $this->t('Started'),
        $this->t('Finished'),
        $this->t('Started by'),
        $this->t('Status'),
        $this->t('Items'),
        $this->t('Problems'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No reconciliation has run yet.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $run_id = $this->reconciler->start('manual');
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    batch_set([
      'title' => $this->t('Reconciling with the origin'),
      'operations' => [[[self::class, 'batchStep'], [$run_id]]],
      'finished' => [self::class, 'batchFinished'],
    ]);
  }

  /**
   * Batch operation: one step of the run, until it is done.
   */
  public static function batchStep(int $run_id, &$context): void {
    $reconciler = \Drupal::service('content_relay_replica.reconciler');
    $context['results']['run'] = $run_id;
    $next = $reconciler->step($run_id);
    $run = $reconciler->load($run_id);
    $context['results']['waiting'] = $next === Reconciler::WAIT;
    $context['message'] = new TranslatableMarkup('@checked item(s) checked.', ['@checked' => $run['counts']['checked'] ?? 0]);
    // Waiting for the origin: cron carries on with the run.
    $context['finished'] = $next === Reconciler::MORE ? 0.5 : 1;
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results): void {
    $run = isset($results['run']) ? \Drupal::service('content_relay_replica.reconciler')->load((int) $results['run']) : NULL;
    if (!empty($results['waiting'])) {
      \Drupal::messenger()->addWarning(new TranslatableMarkup('The origin asked this site to wait before reading more. Cron carries on with the run.'));
      return;
    }
    if (!$success || $run === NULL) {
      \Drupal::messenger()->addError(new TranslatableMarkup('Reconciliation did not finish. Cron continues it.'));
      return;
    }
    \Drupal::messenger()->addStatus(new TranslatableMarkup('Reconciliation finished: @checked checked, @pulled pulled, @deleted deleted, @failed failed, @queued queued for cron.', [
      '@checked' => $run['counts']['checked'] ?? 0,
      '@pulled' => $run['counts']['pulled'] ?? 0,
      '@deleted' => $run['counts']['deleted'] ?? 0,
      '@failed' => $run['counts']['failed'] ?? 0,
      '@queued' => $run['counts']['queued'] ?? 0,
    ]));
  }

}
