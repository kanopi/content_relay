<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay\Contract\ContractRenderer;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\Mapping\MappingRepository;
use Drupal\content_relay_replica\OriginClient;
use Drupal\content_relay_replica\Schema\CompatibilityChecker;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\SubscriptionManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the contract with the origin and proposes changes to it.
 */
final class SubscriptionForm extends FormBase {

  public function __construct(
    protected SubscriptionManager $subscription,
    protected ContractStore $contracts,
    protected OriginPeer $origin,
    protected ContractRenderer $renderer,
    protected SyncableEntityTypes $syncableTypes,
    protected LanguageManagerInterface $languageManager,
    protected OriginClient $originClient,
    protected CompatibilityChecker $checker,
    protected MappingRepository $mappings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_replica.subscription'),
      $container->get('content_relay_replica.contracts'),
      $container->get('content_relay_replica.origin'),
      $container->get('content_relay.contract_renderer'),
      $container->get('content_relay.syncable_entity_types'),
      $container->get('language_manager'),
      $container->get('content_relay_replica.origin_client'),
      $container->get('content_relay_replica.compatibility_checker'),
      $container->get('content_relay_replica.mappings'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_subscription';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $origin = $this->origin->get();
    if ($origin === NULL) {
      $form['unpaired'] = [
        '#markup' => '<p>' . $this->t('This site is not paired with an origin yet. <a href=":url">Pair it</a>.', [':url' => Url::fromRoute('content_relay_replica.pair')->toString()]) . '</p>',
      ];
      return $form;
    }
    $form['origin'] = [
      '#type' => 'item',
      '#title' => $this->t('Origin'),
      '#markup' => $this->t('%origin (@url)', ['%origin' => $origin['label'], '@url' => $origin['url']]),
    ];
    $current = $this->contracts->getCurrent();
    $history = $this->contracts->history();
    $form['current'] = $this->renderer->current($current, NULL, $current ? $history[$current->version()]['created'] : NULL);
    if ($this->subscription->hasPendingProposal()) {
      $form['pending'] = [
        '#type' => 'item',
        '#markup' => $this->t('A change is waiting to reach the origin and will be retried. Until the origin confirms it, the current contract stays in force.'),
      ];
    }

    $terms = $this->subscription->getReplicaTerms();
    $form['terms'] = [
      '#type' => 'details',
      '#title' => $this->t('Change the subscription'),
      '#tree' => TRUE,
    ];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $entity_type) {
      if ($bundles = $this->syncableTypes->getBundles($entity_type_id)) {
        $form['terms']['bundles'][$entity_type_id] = [
          '#type' => 'checkboxes',
          '#title' => $entity_type->getLabel(),
          '#options' => $bundles,
          '#default_value' => $terms['bundles'][$entity_type_id] ?? [],
        ];
      }
    }
    $languages = [];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $languages[$langcode] = $language->getName();
    }
    $form['terms']['language_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Languages'),
      '#options' => [
        SubscriptionContract::LANGUAGES_ALL => $this->t('All languages, including any the origin adds later'),
        SubscriptionContract::LANGUAGES_SELECTED => $this->t('Only the languages selected below'),
      ],
      '#default_value' => $terms['languages']['mode'] ?? SubscriptionContract::LANGUAGES_ALL,
    ];
    $form['terms']['languages'] = [
      '#type' => 'checkboxes',
      '#options' => $languages,
      '#default_value' => $terms['languages']['selected'] ?? [],
      '#states' => ['visible' => [':input[name="terms[language_mode]"]' => ['value' => SubscriptionContract::LANGUAGES_SELECTED]]],
    ];
    $form['terms']['processing'] = [
      '#type' => 'select',
      '#title' => $this->t('Processing'),
      '#options' => [
        'immediate' => $this->t('Immediate'),
        'queued' => $this->t('Queued'),
        'auto' => $this->t('Automatic'),
      ],
      '#default_value' => $terms['processing']['default'] ?? 'immediate',
    ];
    $form['terms']['max_events_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('Most events per minute'),
      '#min' => 1,
      '#description' => $this->t('Leave empty for no limit.'),
      '#default_value' => $terms['delivery']['max_events_per_minute'] ?? NULL,
    ];
    $form['terms']['paused'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pause delivery'),
      '#description' => $this->t('The origin keeps recording changes and delivers them in order when delivery resumes.'),
      '#default_value' => !empty($terms['delivery']['paused']),
    ];
    $form['terms']['encryption'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Encrypt messages and files'),
      '#description' => $this->t('Both sites need an encryption key. Messages are always signed and sent over HTTPS either way.'),
      '#default_value' => ($terms['encryption'] ?? 'none') !== 'none',
    ];
    $form['terms']['require_encryption'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require encryption'),
      '#description' => $this->t('This site then refuses unencrypted messages, however they are signed.'),
      '#default_value' => (bool) $this->configFactory()->get('content_relay.settings')->get('encryption.require'),
    ];
    $form['terms']['submit'] = ['#type' => 'submit', '#value' => $this->t('Propose changes')];

    $form['history'] = $this->renderer->history($history);
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * Refuses bundles newly asked for as they are when they do not fit here.
   *
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // New: not asked for before, whether or not it was granted then.
    $current = $this->subscription->getReplicaTerms()['bundles'] ?? [];
    $new = [];
    foreach ((array) ($form_state->getValue(['terms', 'bundles']) ?? []) as $entity_type_id => $selected) {
      foreach (array_filter((array) $selected) as $bundle) {
        if (!in_array($bundle, $current[$entity_type_id] ?? [], TRUE) && $this->mappings->forSource((string) $entity_type_id, (string) $bundle) === NULL) {
          $new[(string) $entity_type_id][] = (string) $bundle;
        }
      }
    }
    if ($new === []) {
      return;
    }
    try {
      $schema = $this->originClient->schema();
    }
    catch (SubscriptionException $e) {
      $form_state->setErrorByName('terms][bundles', $this->t('The origin schema could not be checked, so new bundles cannot be added as they are: @message', ['@message' => $e->getMessage()]));
      return;
    }
    foreach ($new as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        $origin = $schema[$entity_type_id][$bundle] ?? NULL;
        if ($origin === NULL) {
          continue;
        }
        $problems = $this->checker->check($entity_type_id, $bundle, $origin)['problems'];
        if ($problems !== []) {
          $form_state->setErrorByName('terms][bundles][' . $entity_type_id, $this->t('%bundle cannot be taken as it is: @problems Add a mapping for it first.', [
            '%bundle' => $origin['label'] ?? $bundle,
            '@problems' => implode(' ', $problems),
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('terms');
    $terms = $this->subscription->getReplicaTerms();
    $terms['bundles'] = [];
    foreach ($values['bundles'] ?? [] as $entity_type_id => $selected) {
      if ($selected = array_values(array_filter($selected))) {
        sort($selected);
        $terms['bundles'][$entity_type_id] = $selected;
      }
    }
    ksort($terms['bundles']);
    $previous_mode = $terms['languages']['mode'] ?? SubscriptionContract::LANGUAGES_ALL;
    $selected = array_values(array_filter($values['languages'] ?? []));
    // Switching from all to selected starts from what is received now, so
    // nothing is dropped by accident.
    if ($previous_mode === SubscriptionContract::LANGUAGES_ALL && $values['language_mode'] === SubscriptionContract::LANGUAGES_SELECTED && $selected === []) {
      $selected = array_keys($this->languageManager->getLanguages());
    }
    sort($selected);
    $terms['languages']['mode'] = $values['language_mode'];
    $terms['languages']['selected'] = $values['language_mode'] === SubscriptionContract::LANGUAGES_SELECTED ? $selected : [];
    $terms['processing']['default'] = $values['processing'];
    $terms['delivery']['max_events_per_minute'] = $values['max_events_per_minute'] === '' || $values['max_events_per_minute'] === NULL ? NULL : (int) $values['max_events_per_minute'];
    $terms['delivery']['paused'] = (bool) $values['paused'];
    $terms['encryption'] = empty($values['encryption']) ? 'none' : 'sealed';
    $this->configFactory()->getEditable('content_relay.settings')->set('encryption.require', (bool) $values['require_encryption'])->save();

    $contract = $this->subscription->propose($terms, (int) $this->currentUser()->id());
    if ($contract) {
      $this->messenger()->addStatus($this->t('The origin confirmed contract version @version.', ['@version' => $contract->version()]));
    }
    else {
      $this->messenger()->addWarning($this->t('The origin could not be reached. The change will be retried; the current contract stays in force until it is confirmed.'));
    }
  }

}
