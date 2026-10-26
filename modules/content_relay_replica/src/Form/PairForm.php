<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SubscriptionManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pairs this replica with an origin using a code generated there.
 *
 * A site already paired can pair with further origins; each pairing after the
 * first is named.
 */
final class PairForm extends FormBase {

  public function __construct(
    protected SubscriptionManager $subscription,
    protected OriginPeer $origin,
    protected SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_replica.subscription'),
      $container->get('content_relay_replica.origin'),
      $container->get('content_relay.syncable_entity_types'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_pair';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $paired = $this->origin->all();
    if ($paired !== []) {
      $form['paired'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('This site already receives from'),
        '#items' => array_map(fn (array $origin) => $this->t('%origin (@url)', [
          '%origin' => $origin['label'],
          '@url' => $origin['url'],
        ]), array_values($paired)),
      ];
      $form['pairing'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Name for this pairing'),
        '#description' => $this->t('Lowercase letters, digits and underscores, such as <em>asia</em>. Drush commands use it to say which origin they are for.'),
        '#required' => TRUE,
        '#maxlength' => 64,
      ];
    }
    $form['origin_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Origin address'),
      '#description' => $this->t('The https:// address of the site that publishes the content.'),
      '#required' => TRUE,
    ];
    $form['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pairing code'),
      '#description' => $this->t('Generated on the origin. Dashes, spaces and case do not matter.'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['bundles'] = [
      '#type' => 'details',
      '#title' => $this->t('Content to receive'),
      '#description' => $this->t('Only bundles that exist on this site can be received as they are. The origin may grant fewer than requested; the contract will say which, and why.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $entity_type) {
      if ($bundles = $this->syncableTypes->getBundles($entity_type_id)) {
        $form['bundles'][$entity_type_id] = [
          '#type' => 'checkboxes',
          '#title' => $entity_type->getLabel(),
          '#options' => $bundles,
        ];
      }
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Pair')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!str_starts_with(strtolower((string) $form_state->getValue('origin_url')), 'https://')) {
      $form_state->setErrorByName('origin_url', $this->t('Content Relay pairs only over HTTPS.'));
    }
    if ($form_state->hasValue('pairing')) {
      $pairing = (string) $form_state->getValue('pairing');
      if (preg_match('/^[a-z0-9_]{1,64}$/', $pairing) !== 1) {
        $form_state->setErrorByName('pairing', $this->t('Use lowercase letters, digits and underscores.'));
      }
      elseif (array_key_exists($pairing, $this->origin->all())) {
        $form_state->setErrorByName('pairing', $this->t('%pairing is already paired with an origin.', ['%pairing' => $pairing]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $bundles = [];
    foreach ($form_state->getValue('bundles') ?? [] as $entity_type_id => $selected) {
      if ($selected = array_values(array_filter($selected))) {
        sort($selected);
        $bundles[$entity_type_id] = $selected;
      }
    }
    try {
      $result = $this->subscription->pair(
        (string) $form_state->getValue('origin_url'),
        (string) $form_state->getValue('code'),
        ['bundles' => $bundles],
        (int) $this->currentUser()->id(),
        $form_state->hasValue('pairing') ? (string) $form_state->getValue('pairing') : NULL,
      );
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($this->t('Paired. Contract version @version is in place.', ['@version' => $result['contract']->version()]));
    if ($result['approval_required']) {
      $this->messenger()->addWarning($this->t('The origin requires an administrator there to approve this replica before content flows.'));
    }
    $form_state->setRedirectUrl(Url::fromRoute('content_relay_replica.subscription', ['content_relay_origin_pairing' => $result['pairing']]));
  }

}
