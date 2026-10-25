<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay_origin\ManualPush;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pushes one item to replicas now.
 *
 * Lists the replicas whose contract covers the item with its status on each.
 * Replicas it cannot be pushed to (not syncable, detached by the replica)
 * are shown with the reason and cannot be selected.
 */
final class PushForm extends FormBase {

  public function __construct(
    protected ManualPush $push,
    protected ReportIndex $report,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.manual_push'),
      $container->get('content_relay_origin.report'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_push';
  }

  /**
   * The item from the route.
   */
  private function entity(?string $entity_type_id): ContentEntityInterface {
    $entity = $entity_type_id === NULL ? NULL : $this->getRouteMatch()->getParameter($entity_type_id);
    if (!$entity instanceof ContentEntityInterface) {
      throw new NotFoundHttpException();
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL): array {
    $entity = $this->entity($entity_type_id);
    $form_state->set('entity', $entity);
    $labels = ManualPush::outcomeLabels();
    $statuses = ReportIndex::statusLabels();

    if ($results = $form_state->get('results')) {
      $form['results'] = [
        '#type' => 'table',
        '#caption' => $this->t('Push result'),
        '#header' => [$this->t('Replica'), $this->t('Outcome'), $this->t('Event')],
        '#weight' => -10,
      ];
      foreach ($results as $replica_id => $result) {
        $form['results'][$replica_id] = [
          'replica' => ['#plain_text' => $replica_id],
          'outcome' => ['#plain_text' => (string) ($labels[$result['status']] ?? $result['status']) . ($result['message'] ? ' (' . $result['message'] . ')' : '')],
          'event' => ['#plain_text' => $result['row'] === NULL ? '-' : '#' . $result['row']],
        ];
      }
    }

    $rows = $this->report->itemRows($entity->getEntityTypeId(), (string) $entity->uuid());
    $form['replicas'] = [
      '#type' => 'table',
      '#header' => [
        'select' => $this->t('Push'),
        'replica' => $this->t('Replica'),
        'status' => $this->t('Status'),
        'last_success' => $this->t('Last success'),
      ],
      '#empty' => $this->t('No replica receives this item.'),
    ];
    $pushable = 0;
    foreach ($this->push->targets($entity) as $replica_id => $replica) {
      $reason = $this->push->blockReason($entity, $replica_id);
      $row = $rows[$replica_id] ?? NULL;
      $pushable += (int) ($reason === NULL);
      $form['replicas'][$replica_id] = [
        'select' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Push to @replica', ['@replica' => $replica->label]),
          '#title_display' => 'invisible',
          '#default_value' => $reason === NULL,
          '#disabled' => $reason !== NULL,
        ],
        'replica' => ['#plain_text' => $replica->label],
        'status' => [
          '#plain_text' => $reason !== NULL
            ? $this->t('@status: not pushed', ['@status' => $labels[$reason] ?? $reason])
            : (string) ($statuses[$row['status'] ?? ''] ?? $this->t('Unknown')),
        ],
        'last_success' => ['#plain_text' => empty($row['last_success']) ? '-' : $this->dateFormatter->format((int) $row['last_success'], 'short')],
      ];
    }

    $form['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Processing on the replica'),
      '#options' => [
        ProcessingMode::Immediate->value => $this->t('Immediate: apply now'),
        ProcessingMode::Queued->value => $this->t("Queued: apply from the replica's queue"),
        ProcessingMode::Auto->value => $this->t('Auto: as the replica decides'),
      ],
      '#default_value' => ProcessingMode::Immediate->value,
    ];
    $form['force'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force'),
      '#description' => $this->t('Send the current revision even to replicas that already have it.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Push now'),
      '#disabled' => $pushable === 0,
    ];
    if ($pushable === 0) {
      $form['actions']['reason'] = [
        '#markup' => '<p>' . $this->t('There is no replica this item can be pushed to.') . '</p>',
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->selected($form_state) === []) {
      $form_state->setErrorByName('replicas', $this->t('Select at least one replica.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $form_state->get('entity');
    $results = $this->push->push($entity, $this->selected($form_state), (bool) $form_state->getValue('force'), ProcessingMode::from((string) $form_state->getValue('mode')));
    $form_state->set('results', $results);
    $form_state->setRebuild();
  }

  /**
   * The replicas selected.
   *
   * @return string[]
   *   Replica IDs.
   */
  private function selected(FormStateInterface $form_state): array {
    $selected = [];
    foreach ((array) $form_state->getValue('replicas') as $replica_id => $row) {
      if (!empty($row['select'])) {
        $selected[] = (string) $replica_id;
      }
    }
    return $selected;
  }

}
