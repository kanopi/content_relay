<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Approves, suspends, resumes or revokes a replica.
 */
final class ReplicaActionForm extends ConfirmFormBase {

  /**
   * The replica being changed.
   */
  protected Replica $replica;

  /**
   * The action.
   */
  protected string $action;

  public function __construct(
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_origin.replica_registry'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('logger.channel.content_relay_origin'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_replica_action';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $replica_id = NULL, ?string $action = NULL): array {
    $replica = $replica_id === NULL ? NULL : $this->registry->get($replica_id);
    if ($replica === NULL || !in_array($action, $this->allowedActions($replica), TRUE)) {
      throw new NotFoundHttpException();
    }
    $this->replica = $replica;
    $this->action = (string) $action;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    $args = ['%replica' => $this->replica->label];
    return match ($this->action) {
      'approve' => $this->t('Approve replica %replica?', $args),
      'suspend' => $this->t('Suspend replica %replica?', $args),
      'resume' => $this->t('Resume replica %replica?', $args),
      default => $this->t('Revoke replica %replica?', $args),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return match ($this->action) {
      'approve' => $this->t('Events start flowing once the replica has acknowledged its contract.'),
      'suspend' => $this->t('Events keep being recorded and are delivered in order when the replica is resumed.'),
      'resume' => $this->t('Queued events are delivered in order.'),
      default => $this->t('The replica stops receiving events and its messages are refused. It keeps the content it has. This cannot be undone; the replica would have to pair again.'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return match ($this->action) {
      'approve' => $this->t('Approve'),
      'suspend' => $this->t('Suspend'),
      'resume' => $this->t('Resume'),
      default => $this->t('Revoke'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('content_relay_origin.replicas');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = $this->replica->id;
    switch ($this->action) {
      case 'approve':
        $this->registry->setApproved($id, TRUE);
        $this->contracts->activateIfReady($id);
        break;

      case 'suspend':
        $this->registry->setStatus($id, Replica::SUSPENDED);
        break;

      case 'resume':
        $this->registry->setStatus($id, Replica::ACTIVE);
        break;

      case 'revoke':
        $this->registry->setStatus($id, Replica::REVOKED);
        break;
    }
    $this->logger->notice('@user chose to @action replica @replica.', [
      '@user' => $this->currentUser()->getAccountName(),
      '@action' => $this->action,
      '@replica' => $id,
    ]);
    $this->messenger()->addStatus($this->t('Replica %replica updated.', ['%replica' => $this->replica->label]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Actions possible from a replica's current state.
   *
   * @return string[]
   *   Actions.
   */
  protected function allowedActions(Replica $replica): array {
    return match ($replica->status) {
      Replica::PENDING => $replica->approved ? ['revoke'] : ['approve', 'revoke'],
      Replica::ACTIVE => ['suspend', 'revoke'],
      Replica::SUSPENDED => ['resume', 'revoke'],
      default => [],
    };
  }

}
