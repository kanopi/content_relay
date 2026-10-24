<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay\Contract\ContractRenderer;
use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\Replica;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shows a replica's contract and lets the origin change what it allows.
 *
 * Changing the allowed bundles is the origin's side of the contract: it
 * proposes a new version and sends it to the replica. Withdrawn bundles are
 * kept on the replica as items detached by the origin, not deleted.
 */
final class ReplicaContractForm extends FormBase {

  public function __construct(
    protected ReplicaRegistry $registry,
    protected ContractManager $contracts,
    protected ContractRenderer $renderer,
    protected SyncScope $scope,
    protected SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_origin.replica_registry'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('content_relay.contract_renderer'),
      $container->get('content_relay_origin.sync_scope'),
      $container->get('content_relay.syncable_entity_types'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_replica_contract';
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
    $form['#title'] = $this->t('Contract with %replica', ['%replica' => $replica->label]);

    $history = $this->contracts->history($replica->id);
    $active = $this->contracts->getActive($replica->id);
    $since = $active ? ($history[$active->version()]['acknowledged'] ?? NULL) : NULL;
    $from = $active ? ($history[$active->version()]['effective_from'] ?? NULL) : NULL;
    $form['current'] = $this->renderer->current($active, $this->contracts->getPending($replica->id), $since, $replica->label, $from);

    if ($replica->status !== Replica::REVOKED) {
      $latest = $this->contracts->getLatest($replica->id);
      $allowed = $latest?->terms['origin_terms']['allowed_bundles'] ?? NULL;
      $options = [];
      foreach ($this->scope->getPublishedBundles() as $entity_type_id => $bundles) {
        $labels = $this->syncableTypes->getBundles($entity_type_id);
        foreach ($bundles as $bundle) {
          $options[$entity_type_id . '.' . $bundle] = $labels[$bundle] ?? $entity_type_id . ':' . $bundle;
        }
      }
      $selected = [];
      foreach ($allowed ?? [] as $entity_type_id => $bundles) {
        foreach ($bundles as $bundle) {
          $selected[] = $entity_type_id . '.' . $bundle;
        }
      }
      $form['allowed'] = [
        '#type' => 'details',
        '#title' => $this->t('What this replica may receive'),
        '#open' => TRUE,
      ];
      $form['allowed']['limit'] = [
        '#type' => 'radios',
        '#options' => [
          'all' => $this->t('Everything this site publishes, now and later'),
          'selected' => $this->t('Only the bundles selected below'),
        ],
        '#default_value' => $allowed === NULL ? 'all' : 'selected',
      ];
      $form['allowed']['bundles'] = [
        '#type' => 'checkboxes',
        '#options' => $options,
        '#default_value' => $selected,
        '#states' => ['visible' => [':input[name="limit"]' => ['value' => 'selected']]],
      ];
      $form['allowed']['submit'] = ['#type' => 'submit', '#value' => $this->t('Propose new contract')];
    }

    $form['history'] = $this->renderer->history($history);
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $replica_id = (string) $form_state->get('replica_id');
    $allowed = NULL;
    if ($form_state->getValue('limit') === 'selected') {
      $allowed = [];
      foreach (array_filter($form_state->getValue('bundles') ?? []) as $key) {
        [$entity_type_id, $bundle] = explode('.', (string) $key, 2);
        $allowed[$entity_type_id][] = $bundle;
      }
    }
    $origin_terms = ['allowed_bundles' => $allowed] + ($this->contracts->getLatest($replica_id)?->terms['origin_terms'] ?? []);
    $contract = $this->contracts->propose($replica_id, NULL, $origin_terms, 'origin', (int) $this->currentUser()->id());
    if ($this->contracts->push($replica_id, TRUE)) {
      $this->messenger()->addStatus($this->t('Contract version @version is in force.', ['@version' => $contract->version()]));
    }
    else {
      $this->messenger()->addWarning($this->t('Contract version @version was proposed. The replica has not acknowledged it yet; it will be sent again, and the previous version stays in force until then.', ['@version' => $contract->version()]));
    }
  }

}
