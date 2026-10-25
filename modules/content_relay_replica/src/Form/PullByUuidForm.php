<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\Component\Uuid\Uuid;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\Puller;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pulls an item from the origin by its UUID, whether or not it is here yet.
 */
final class PullByUuidForm extends FormBase {

  public function __construct(
    protected Puller $puller,
    protected ContractStore $contracts,
    protected SyncStateStore $syncState,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.puller'),
      $container->get('content_relay_replica.contracts'),
      $container->get('content_relay_replica.sync_state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_pull_uuid';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $types = array_keys($this->contracts->getCurrent()?->effectiveBundles() ?? []);
    if ($types === []) {
      $form['none'] = ['#markup' => '<p>' . $this->t('This site does not receive any content yet.') . '</p>'];
      return $form;
    }
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type on the origin'),
      '#options' => array_combine($types, $types),
      '#required' => TRUE,
    ];
    $form['uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UUID'),
      '#required' => TRUE,
      '#size' => 40,
      '#maxlength' => 36,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Pull')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!Uuid::isValid(trim((string) $form_state->getValue('uuid')))) {
      $form_state->setErrorByName('uuid', $this->t('Enter a UUID.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity_type_id = (string) $form_state->getValue('entity_type');
    $uuid = strtolower(trim((string) $form_state->getValue('uuid')));
    // An item already here may have local edits: the item form shows them.
    if ($this->syncState->get($entity_type_id, $uuid) !== NULL) {
      $form_state->setRedirect('content_relay_replica.pull', ['entity_type_id' => $entity_type_id, 'uuid' => $uuid]);
      return;
    }
    try {
      $result = $this->puller->pull($entity_type_id, $uuid);
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addError($this->t('The origin could not give the item: @message', ['@message' => $e->getMessage()]));
      return;
    }
    $message = ItemPullForm::describe($result);
    $result->httpStatus >= 400 ? $this->messenger()->addError($message) : $this->messenger()->addStatus($message);
  }

}
