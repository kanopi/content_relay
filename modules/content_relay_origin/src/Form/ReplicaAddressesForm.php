<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay\Http\IpAllowlist;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The addresses a replica may connect from.
 */
final class ReplicaAddressesForm extends FormBase {

  public function __construct(
    protected ReplicaRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('content_relay_origin.replica_registry'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_replica_addresses';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $replica_id = NULL): array {
    $replica = $replica_id === NULL ? NULL : $this->registry->get($replica_id);
    if ($replica === NULL) {
      throw new NotFoundHttpException();
    }
    $form_state->set('replica_id', $replica->id);
    $form['#title'] = $this->t('Allowed addresses for %label', ['%label' => $replica->label]);
    $form['allowed_ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Addresses and ranges'),
      '#description' => $this->t('One per line, such as 203.0.113.10 or 2001:db8::/32. Messages and read requests from this replica are refused from anywhere else. Leave empty to accept any address; every message is signed either way.'),
      '#default_value' => implode("\n", $replica->allowedIps),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $form_state->set('parsed', IpAllowlist::parse((string) $form_state->getValue('allowed_ips')));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('allowed_ips', $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $replica_id = $form_state->get('replica_id');
    $this->registry->setAllowedIps($replica_id, $form_state->get('parsed'));
    $this->logger('content_relay_origin')->notice('@user changed the allowed addresses of replica @replica.', [
      '@user' => $this->currentUser()->getAccountName(),
      '@replica' => $replica_id,
    ]);
    $this->messenger()->addStatus($this->t('Allowed addresses saved.'));
    $form_state->setRedirect('content_relay_origin.replicas');
  }

}
