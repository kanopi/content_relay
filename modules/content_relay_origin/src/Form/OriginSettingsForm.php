<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\PairingCodes;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures what the origin publishes, and how it delivers and pairs.
 */
final class OriginSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected SyncableEntityTypes $syncableTypes,
    protected ContractManager $contracts,
    protected ReplicaRegistry $registry,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('content_relay.syncable_entity_types'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('content_relay_origin.replica_registry'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['content_relay_origin.settings', 'content_relay.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('content_relay_origin.settings');
    $published = $config->get('bundles') ?? [];

    $form['bundles'] = [
      '#type' => 'details',
      '#title' => $this->t('Published content'),
      '#description' => $this->t('Changes to items of these bundles are sent to replicas whose contract includes them. Only content entity types with a UUID can be synced; embedded types such as paragraphs travel inside their parent and are not listed.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $entity_type) {
      $bundles = $this->syncableTypes->getBundles($entity_type_id);
      if ($bundles === []) {
        continue;
      }
      $form['bundles'][$entity_type_id] = [
        '#type' => 'checkboxes',
        '#title' => $entity_type->getLabel(),
        '#options' => $bundles,
        '#default_value' => $published[$entity_type_id] ?? [],
      ];
    }

    $form['pairing'] = [
      '#type' => 'details',
      '#title' => $this->t('Pairing'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['pairing']['code_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Pairing code length'),
      '#description' => $this->t('Characters in each code, from @min to @max. Each character carries five bits.', [
        '@min' => PairingCodes::MIN_LENGTH,
        '@max' => PairingCodes::MAX_LENGTH,
      ]),
      '#default_value' => $config->get('pairing.code_length'),
      '#required' => TRUE,
    ];
    $form['pairing']['code_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Pairing code lifetime, in minutes'),
      '#min' => 1,
      '#default_value' => (int) ($config->get('pairing.code_lifetime') / 60),
      '#required' => TRUE,
    ];
    $form['pairing']['flood_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed pairing attempts allowed per IP address per hour'),
      '#min' => 1,
      '#default_value' => $config->get('pairing.flood_limit'),
      '#required' => TRUE,
    ];
    $form['pairing']['require_approval'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hold events until an administrator approves each new replica'),
      '#default_value' => $config->get('pairing.require_approval'),
    ];

    $form['delivery'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery'),
      '#tree' => TRUE,
    ];
    $form['delivery']['max_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('Attempts before an event is dead-lettered'),
      '#min' => 1,
      '#default_value' => $config->get('delivery.max_attempts'),
    ];
    $form['delivery']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout, in seconds'),
      '#min' => 1,
      '#default_value' => $config->get('delivery.timeout'),
    ];
    $form['retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to keep acknowledged events in the event log'),
      '#min' => 1,
      '#default_value' => $config->get('retention_days'),
    ];
    $form['encryption'] = [
      '#type' => 'details',
      '#title' => $this->t('Encryption'),
      '#tree' => TRUE,
    ];
    $form['encryption']['require'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require encryption'),
      '#description' => $this->t('Refuse unencrypted messages from every replica, however they are signed, and make every contract encrypted. Each replica then needs an encryption key. Without this, a replica chooses encryption in its contract.'),
      '#default_value' => (bool) $this->config('content_relay.settings')->get('encryption.require'),
    ];
    $form['encryption']['transition_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Transition window, in minutes'),
      '#description' => $this->t('After a contract switches encryption on, unencrypted messages from that replica are still accepted this long, for those already on their way.'),
      '#min' => 0,
      '#default_value' => intdiv((int) $this->config('content_relay.settings')->get('encryption.transition_window'), 60),
    ];
    $form['not_syncable_behavior'] = [
      '#type' => 'radios',
      '#title' => $this->t('When an item replicas already have stops syncing'),
      '#options' => [
        NotSyncableFlags::DETACH => $this->t('Replicas keep their copy, no longer updated'),
        NotSyncableFlags::REMOVE => $this->t('Replicas delete their copy'),
      ],
      '#default_value' => $config->get('not_syncable.behavior') ?? NotSyncableFlags::DETACH,
      '#description' => $this->t('The default; it can be chosen each time syncing stops for an item.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $length = (int) $form_state->getValue(['pairing', 'code_length']);
    if ($length < PairingCodes::MIN_LENGTH || $length > PairingCodes::MAX_LENGTH) {
      $form_state->setErrorByName('pairing][code_length', $this->t('Pairing codes must be between @min and @max characters.', [
        '@min' => PairingCodes::MIN_LENGTH,
        '@max' => PairingCodes::MAX_LENGTH,
      ]));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $bundles = [];
    foreach ($form_state->getValue('bundles') ?? [] as $entity_type_id => $selected) {
      $selected = array_values(array_filter($selected));
      if ($selected) {
        sort($selected);
        $bundles[$entity_type_id] = $selected;
      }
    }
    ksort($bundles);
    $this->config('content_relay_origin.settings')
      ->set('bundles', $bundles)
      ->set('pairing.code_length', (int) $form_state->getValue(['pairing', 'code_length']))
      ->set('pairing.code_lifetime', 60 * (int) $form_state->getValue(['pairing', 'code_lifetime']))
      ->set('pairing.flood_limit', (int) $form_state->getValue(['pairing', 'flood_limit']))
      ->set('pairing.require_approval', (bool) $form_state->getValue(['pairing', 'require_approval']))
      ->set('delivery.max_attempts', (int) $form_state->getValue(['delivery', 'max_attempts']))
      ->set('delivery.timeout', (int) $form_state->getValue(['delivery', 'timeout']))
      ->set('retention_days', (int) $form_state->getValue('retention_days'))
      ->set('not_syncable.behavior', (string) $form_state->getValue('not_syncable_behavior'))
      ->save();
    $require = (bool) $form_state->getValue(['encryption', 'require']);
    $changed = $require !== (bool) $this->config('content_relay.settings')->get('encryption.require');
    $this->config('content_relay.settings')
      ->set('encryption.require', $require)
      ->set('encryption.transition_window', 60 * (int) $form_state->getValue(['encryption', 'transition_window']))
      ->save();
    if ($changed) {
      // Every contract states each side's policy: new versions carry it.
      foreach ($this->registry->getAll() as $replica) {
        if ($replica->receivesEvents() && $this->contracts->getLatest($replica->id) !== NULL) {
          $this->contracts->propose($replica->id, NULL, NULL, 'origin', (int) $this->currentUser()->id());
        }
      }
      $this->messenger()->addStatus($this->t('Replicas will be sent new contract versions with the encryption policy.'));
    }
    parent::submitForm($form, $form_state);
  }

}
