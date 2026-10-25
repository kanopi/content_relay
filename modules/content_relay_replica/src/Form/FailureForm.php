<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\Conflicts;
use Drupal\content_relay_replica\FailedEventStore;
use Drupal\content_relay_replica\Puller;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One failure: what went wrong, what differs, and what to do about it.
 */
final class FailureForm extends FormBase {

  public function __construct(
    protected FailedEventStore $failures,
    protected SyncApplier $applier,
    protected Puller $puller,
    protected Conflicts $conflicts,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.failed_events'),
      $container->get('content_relay_replica.applier'),
      $container->get('content_relay_replica.puller'),
      $container->get('content_relay_replica.conflicts'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_failure';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $failure_id = NULL): array {
    $failure = $failure_id === NULL ? NULL : $this->failures->load($failure_id);
    if ($failure === NULL) {
      throw new NotFoundHttpException();
    }
    $form_state->set('failure', $failure);
    $form['#title'] = $this->t('Failed to save %label', ['%label' => $failure['label'] ?: $failure['uuid']]);
    $rows = [
      [$this->t('Item'), sprintf('%s %s %s', $failure['entity_type'], $failure['bundle'], $failure['uuid'])],
      [
        $this->t('Event'),
        sprintf('%s (%s, origin revision %d)', $failure['event_id'], $failure['operation'], $failure['source_revision']),
      ],
      [$this->t('Status'), $failure['status'] . ($failure['dismiss_reason'] ? ': ' . $failure['dismiss_reason'] : '')],
      [$this->t('Kind'), $failure['failure_class']],
      [$this->t('Error'), $failure['error_code'] . ': ' . $failure['message']],
      [$this->t('Attempts'), $failure['attempts']],
      [$this->t('First failed'), $this->dateFormatter->format((int) $failure['first_failed'], 'long')],
      [$this->t('Last failed'), $this->dateFormatter->format((int) $failure['last_failed'], 'long')],
    ];
    $form['details'] = [
      '#type' => 'table',
      '#rows' => array_map(fn (array $row) => [['data' => $row[0], 'header' => TRUE], (string) $row[1]], $rows),
    ];
    $violations = json_decode((string) $failure['violations'], TRUE) ?: [];
    $form['violations'] = [
      '#type' => 'table',
      '#caption' => $this->t('Field values that failed validation'),
      '#header' => [$this->t('Field'), $this->t('Language'), $this->t('Problem')],
      '#rows' => array_map(fn (array $violation) => [$violation['field'], $violation['langcode'], $violation['message']], $violations),
      '#access' => $violations !== [],
    ];
    $dependencies = $this->failures->getDependencies((int) $failure['id']);
    $form['dependencies'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Waiting for'),
      '#items' => array_map(fn (array $d) => $d['entity_type'] . ' ' . $d['uuid'], $dependencies),
      '#access' => $dependencies !== [],
    ];
    try {
      $event = $this->failures->getEvent($failure);
      $diff = $event->snapshot === NULL ? [] : $this->conflicts->diffSnapshot($failure['entity_type'], $failure['uuid'], $event->snapshot);
      $form['diff'] = ItemPullForm::diffTable([
        'local' => $this->t('On this site'),
        'origin' => $this->t('In the failed event'),
        'rows' => $diff,
      ]);
      $form['diff']['#empty'] = $this->t('No differences to show; the item may not be here yet.');
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $form['payload'] = ['#markup' => '<p>' . $this->t('The stored event cannot be read: @message', ['@message' => $e->getMessage()]) . '</p>'];
    }

    $open = $failure['status'] === FailedEventStore::OPEN && $this->currentUser()->hasPermission('pull content relay items');
    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reason for dismissing'),
      '#description' => $this->t('Dismissing records who and why; the item stays out of sync.'),
      '#access' => $open,
    ];
    $form['actions'] = ['#type' => 'actions', '#access' => $open];
    $form['actions']['retry'] = ['#type' => 'submit', '#value' => $this->t('Retry'), '#name' => 'retry'];
    $form['actions']['pull'] = ['#type' => 'submit', '#value' => $this->t('Pull fresh'), '#name' => 'pull'];
    $form['actions']['dismiss'] = ['#type' => 'submit', '#value' => $this->t('Dismiss'), '#name' => 'dismiss'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'dismiss' && trim((string) $form_state->getValue('reason')) === '') {
      $form_state->setErrorByName('reason', $this->t('Say why it is dismissed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $failure = $form_state->get('failure');
    $action = $form_state->getTriggeringElement()['#name'] ?? '';
    $log = $this->logger('content_relay_replica');
    $context = ['@user' => $this->currentUser()->getAccountName(), '@id' => $failure['id'], '@action' => $action];
    $log->notice('@user chose @action on failure @id.', $context);
    if ($action === 'dismiss') {
      $this->failures->dismiss((int) $failure['id'], (int) $this->currentUser()->id(), trim((string) $form_state->getValue('reason')));
      $this->messenger()->addStatus($this->t('Dismissed. The item stays out of sync.'));
      $form_state->setRedirect('content_relay_replica.failures');
      return;
    }
    try {
      $result = $action === 'pull'
        ? $this->puller->pull($failure['entity_type'], $failure['uuid'], TRUE)
        : $this->applier->retry((int) $failure['id']);
    }
    catch (SubscriptionException | \InvalidArgumentException | \RuntimeException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $message = ItemPullForm::describe($result);
    $result->httpStatus >= 400 ? $this->messenger()->addError($message) : $this->messenger()->addStatus($message);
  }

}
