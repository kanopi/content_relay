<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\content_relay_origin\SendingSettings;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * How this origin sends to one replica.
 *
 * Each group of settings either follows the site-wide settings or is the
 * replica's own.
 */
final class ReplicaSendingForm extends FormBase {

  public function __construct(
    protected ReplicaRegistry $registry,
    protected SendingSettings $sending,
    protected ContractManager $contracts,
    protected SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_origin.replica_registry'),
      $container->get('content_relay_origin.sending_settings'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('content_relay.syncable_entity_types'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_replica_sending';
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
    $form['#title'] = $this->t('Sending to %replica', ['%replica' => $replica->label]);
    $own = $this->sending->pairing($replica->id)?->getSettings() ?? [];
    $value = fn (string $path) => $this->sending->get($path, $replica->id);
    $form['#tree'] = TRUE;
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Each group follows the <a href=":url">site-wide settings</a> unless it is given its own here.', [
        ':url' => Url::fromRoute('content_relay_origin.settings')->toString(),
      ]) . '</p>',
    ];

    $form['bundles'] = $this->group('bundles', $this->t('Published content'), isset($own['bundles']), $this->t('Bundles this replica may receive. Its contract offers no others.'));
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $entity_type) {
      if ($bundles = $this->syncableTypes->getBundles($entity_type_id)) {
        $form['bundles']['values'][$entity_type_id] = [
          '#type' => 'checkboxes',
          '#title' => $entity_type->getLabel(),
          '#options' => $bundles,
          '#default_value' => ($value('bundles') ?? [])[$entity_type_id] ?? [],
        ];
      }
    }

    $form['delivery'] = $this->group('delivery', $this->t('Delivery'), isset($own['delivery']));
    foreach ([
      'batch_size' => $this->t('Events sent per queue run'),
      'max_attempts' => $this->t('Attempts before an event is dead-lettered'),
      'backoff_base' => $this->t('First retry delay, in seconds'),
      'backoff_max' => $this->t('Longest retry delay, in seconds'),
      'timeout' => $this->t('Request timeout, in seconds'),
    ] as $key => $title) {
      $form['delivery']['values'][$key] = [
        '#type' => 'number',
        '#title' => $title,
        '#min' => 1,
        '#default_value' => $value('delivery.' . $key),
      ];
    }

    $form['read_limit'] = $this->group('read_limit', $this->t('Read limit'), isset($own['read_limit']), $this->t('Items this replica may read per window, for reconciliation and pulls.'));
    $form['read_limit']['values']['items'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per window'),
      '#description' => $this->t('0 for no limit.'),
      '#min' => 0,
      '#default_value' => $value('read_limit.items'),
    ];
    $form['read_limit']['values']['window'] = [
      '#type' => 'number',
      '#title' => $this->t('Window, in seconds'),
      '#min' => 1,
      '#default_value' => $value('read_limit.window'),
    ];

    $form['not_syncable'] = $this->group('not_syncable', $this->t('Items that stop syncing'), isset($own['not_syncable']));
    $form['not_syncable']['values']['behavior'] = [
      '#type' => 'radios',
      '#title' => $this->t('When an item this replica already has stops syncing'),
      '#options' => [
        NotSyncableFlags::DETACH => $this->t('It keeps its copy, no longer updated'),
        NotSyncableFlags::REMOVE => $this->t('It deletes its copy'),
      ],
      '#default_value' => $value('not_syncable.behavior') ?? NotSyncableFlags::DETACH,
    ];

    $form['files'] = $this->group('files', $this->t('Files'), isset($own['files']));
    $form['files']['values']['link_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Download links last, in seconds'),
      '#min' => 60,
      '#default_value' => $value('files.link_lifetime'),
    ];

    $form['encryption'] = $this->group('encryption', $this->t('Encryption'), isset($own['encryption']));
    $form['encryption']['values']['require'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require encryption'),
      '#description' => $this->t('Refuse unencrypted messages from this replica and make its contract encrypted. It then needs an encryption key.'),
      '#default_value' => (bool) $value('encryption.require'),
    ];
    $form['encryption']['values']['transition_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Transition window, in seconds'),
      '#min' => 0,
      '#default_value' => $value('encryption.transition_window'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $replica = $this->registry->get((string) $form_state->get('replica_id'));
    if ($replica === NULL) {
      return;
    }
    $pairing = $this->sending->ensurePairing($replica->id, $replica->label);
    $before = [
      'bundles' => $this->sending->get('bundles', $replica->id),
      'require' => (bool) $this->sending->get('encryption.require', $replica->id),
    ];
    $settings = $pairing->getSettings();
    foreach (SendingSettings::KEYS as $group) {
      if (!$form_state->getValue([$group, 'own'])) {
        unset($settings[$group]);
        continue;
      }
      $values = (array) $form_state->getValue([$group, 'values']);
      $settings[$group] = match ($group) {
        'bundles' => $this->bundles($values),
        'not_syncable' => ['behavior' => (string) $values['behavior']],
        'encryption' => [
          'require' => (bool) $values['require'],
          'transition_window' => (int) $values['transition_window'],
        ],
        default => array_map('intval', $values),
      };
    }
    $pairing->set('settings', $settings)->save();
    $this->messenger()->addStatus($this->t('The settings for %replica are saved.', ['%replica' => $replica->label]));

    // What a contract offers or requires changed: send a new version.
    $changed = $before['bundles'] !== $this->sending->get('bundles', $replica->id)
      || $before['require'] !== (bool) $this->sending->get('encryption.require', $replica->id);
    if ($changed && $replica->receivesEvents() && $this->contracts->getLatest($replica->id) !== NULL) {
      $this->contracts->propose($replica->id, NULL, NULL, 'origin', (int) $this->currentUser()->id());
      $this->messenger()->addStatus($this->t('%replica will be sent a new contract version.', ['%replica' => $replica->label]));
    }
    $form_state->setRedirect('content_relay_origin.replicas');
  }

  /**
   * A group of settings with its own-or-site-wide switch.
   */
  protected function group(string $key, mixed $title, bool $own, mixed $description = NULL): array {
    return [
      '#type' => 'details',
      '#title' => $title,
      '#description' => $description,
      '#open' => $own,
      'own' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Its own settings, rather than the site-wide ones'),
        '#default_value' => $own,
      ],
      'values' => [
        '#type' => 'container',
        '#states' => ['visible' => [':input[name="' . $key . '[own]"]' => ['checked' => TRUE]]],
      ],
    ];
  }

  /**
   * Bundles as checked, by entity type.
   */
  protected function bundles(array $values): array {
    $bundles = [];
    foreach ($values as $entity_type_id => $selected) {
      if ($selected = array_values(array_filter((array) $selected))) {
        sort($selected);
        $bundles[$entity_type_id] = $selected;
      }
    }
    ksort($bundles);
    return $bundles;
  }

}
