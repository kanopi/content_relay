<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\Adopter;
use Drupal\content_relay_replica\Reconciler;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reconciliation run history, and a button to run one now.
 *
 * For the origin in the path; on the report, for the only origin, or else a
 * list of the origins to choose from.
 */
final class ReconcileForm extends FormBase {

  public function __construct(
    protected Reconciler $reconciler,
    protected Adopter $adopter,
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ActivePairing $active,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.reconciler'),
      $container->get('content_relay_replica.adopter'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('content_relay_replica.active_pairing'),
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
    $ids = $this->active->ids();
    $pairing = $this->active->current() ?? (count($ids) === 1 ? $ids[0] : NULL);
    if ($pairing === NULL) {
      $links = [];
      foreach ($ids as $id) {
        $links[] = [
          '#type' => 'link',
          '#title' => $this->entityTypeManager->getStorage('content_relay_origin_pairing')->load($id)?->label() ?? $id,
          '#url' => Url::fromRoute('content_relay_replica.pairing_reconcile', ['content_relay_origin_pairing' => $id]),
        ];
      }
      $form['origins'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Reconcile with which origin?'),
        '#items' => $links,
        '#empty' => $this->t('This site does not receive from any origin yet.'),
      ];
      return $form;
    }
    $form_state->set('pairing', $pairing);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Reconciliation compares every item this site receives with the origin, pulls what differs or is missing, and deletes what the origin no longer has. Content created on this site, items detached here and items in conflict are left alone.') . '</p>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['run'] = ['#type' => 'submit', '#value' => $this->t('Run reconciliation now')];
    $form['adopt'] = [
      '#type' => 'details',
      '#title' => $this->t('Adopt existing content'),
      '#description' => $this->t("For a site set up from a copy of the origin's database: items that are already here, under the same UUIDs, are left alone by sync until adopted. Adopting marks those identical to the origin's as in sync and lists those that differ as conflicts to review. Items already synced are skipped, so it can be run again."),
    ];
    $form['adopt']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Adopt existing content'),
      '#name' => 'adopt',
      '#submit' => ['::adopt'],
    ];

    $rows = [];
    foreach ($this->active->run($pairing, fn () => $this->reconciler->history()) as $run) {
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
    $pairing = (string) $form_state->get('pairing');
    try {
      $run_id = $this->active->run($pairing, fn () => $this->reconciler->start('manual'));
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    batch_set([
      'title' => $this->t('Reconciling with the origin'),
      'operations' => [[[self::class, 'batchStep'], [$run_id, $pairing]]],
      'finished' => [self::class, 'batchFinished'],
    ]);
  }

  /**
   * Submit handler: adopts existing content, a page at a time.
   */
  public function adopt(array &$form, FormStateInterface $form_state): void {
    $pairing = (string) $form_state->get('pairing');
    try {
      $bundles = $this->active->run($pairing, fn () => $this->adopter->bundles());
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    batch_set([
      'title' => $this->t('Adopting existing content'),
      'operations' => array_map(fn (array $pair) => [[self::class, 'adoptStep'], [...$pair, $pairing]], $bundles),
      'finished' => [self::class, 'adoptFinished'],
    ]);
  }

  /**
   * Batch operation: adopts one bundle, a page per request.
   */
  public static function adoptStep(string $entity_type_id, string $bundle, string $pairing, &$context): void {
    $after = $context['sandbox']['after'] ?? '';
    $page = \Drupal::service('content_relay_replica.active_pairing')->run($pairing, fn () => \Drupal::service('content_relay_replica.adopter')->adoptPage($entity_type_id, $bundle, $after));
    foreach ($page['counts'] as $outcome => $number) {
      $context['results'][$outcome] = ($context['results'][$outcome] ?? 0) + $number;
    }
    $context['sandbox']['after'] = $page['next'];
    $context['message'] = new TranslatableMarkup('Adopting @type @bundle.', [
      '@type' => $entity_type_id,
      '@bundle' => $bundle,
    ]);
    $context['finished'] = $page['next'] === NULL ? 1 : 0.5;
  }

  /**
   * Batch finished callback for adopting.
   */
  public static function adoptFinished(bool $success, array $results): void {
    if (!$success) {
      \Drupal::messenger()->addError(new TranslatableMarkup('Adopting did not finish; run it again to carry on.'));
      return;
    }
    \Drupal::messenger()->addStatus(new TranslatableMarkup('Adopted @adopted item(s) as in sync; @edited differ from the origin and are listed as conflicts; @missing are not here yet and come with the next reconciliation.', [
      '@adopted' => $results['adopted'] ?? 0,
      '@edited' => $results['adopted_edited'] ?? 0,
      '@missing' => $results['missing'] ?? 0,
    ]));
  }

  /**
   * Batch operation: one step of the run, until it is done.
   */
  public static function batchStep(int $run_id, string $pairing, &$context): void {
    $reconciler = \Drupal::service('content_relay_replica.reconciler');
    $context['results']['run'] = $run_id;
    $next = \Drupal::service('content_relay_replica.active_pairing')->run($pairing, fn () => $reconciler->step($run_id));
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
