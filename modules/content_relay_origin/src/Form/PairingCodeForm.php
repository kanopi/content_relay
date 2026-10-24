<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_origin\PairingCodes;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates a one-time pairing code, optionally limited to some bundles.
 */
final class PairingCodeForm extends FormBase {

  public function __construct(
    protected PairingCodes $codes,
    protected SyncScope $scope,
    protected SyncableEntityTypes $syncableTypes,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_origin.pairing_codes'),
      $container->get('content_relay_origin.sync_scope'),
      $container->get('content_relay.syncable_entity_types'),
      $container->get('date.formatter'),
      $container->get('logger.channel.content_relay_origin'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_pairing_code';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($generated = $form_state->get('generated')) {
      $form['code'] = [
        '#type' => 'item',
        '#title' => $this->t('Pairing code'),
        '#markup' => '<code class="content-relay-pairing-code">' . $generated['code'] . '</code>',
        '#description' => $this->t('Enter this code on the replica before @time. It works once and is not shown again.', ['@time' => $this->dateFormatter->format($generated['expires'], 'short')]),
      ];
    }

    $published = $this->scope->getPublishedBundles();
    $options = [];
    foreach ($published as $entity_type_id => $bundles) {
      $labels = $this->syncableTypes->getBundles($entity_type_id);
      foreach ($bundles as $bundle) {
        $options[$entity_type_id . '.' . $bundle] = $labels[$bundle] ?? $entity_type_id . ':' . $bundle;
      }
    }
    $form['limit'] = [
      '#type' => 'radios',
      '#title' => $this->t('The replica may receive'),
      '#options' => [
        'all' => $this->t('Everything this site publishes, now and later'),
        'selected' => $this->t('Only the bundles selected below'),
      ],
      '#default_value' => 'all',
    ];
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Bundles'),
      '#options' => $options,
      '#states' => ['visible' => [':input[name="limit"]' => ['value' => 'selected']]],
    ];
    if ($options === []) {
      $form['bundles']['#description'] = $this->t('Nothing is published yet. Choose bundles on the settings tab.');
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Generate code')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('limit') === 'selected' && !array_filter($form_state->getValue('bundles') ?? [])) {
      $form_state->setErrorByName('bundles', $this->t('Select at least one bundle, or allow everything.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $allowed = NULL;
    if ($form_state->getValue('limit') === 'selected') {
      $allowed = [];
      foreach (array_filter($form_state->getValue('bundles')) as $key) {
        [$entity_type_id, $bundle] = explode('.', (string) $key, 2);
        $allowed[$entity_type_id][] = $bundle;
      }
    }
    $generated = $this->codes->generate($allowed, (int) $this->currentUser()->id());
    $this->logger->notice('@user generated a pairing code (@scope).', [
      '@user' => $this->currentUser()->getAccountName(),
      '@scope' => $allowed === NULL ? 'all bundles' : implode(', ', array_filter($form_state->getValue('bundles'))),
    ]);
    $form_state->set('generated', $generated);
    $form_state->setRebuild();
  }

}
