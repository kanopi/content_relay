<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay\Http\IpAllowlist;
use Drupal\content_relay_replica\LanguageMap;
use Drupal\content_relay_replica\LanguageSubscription;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * How this replica applies what it receives.
 *
 * What it receives is the contract's business, on the subscription page.
 */
final class ReplicaSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected LanguageMap $languageMap,
    protected LanguageSubscription $languages,
    protected EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('content_relay_replica.language_map'),
      $container->get('content_relay_replica.language_subscription'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['content_relay_replica.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('content_relay_replica.settings');

    $form['conflicts'] = [
      '#type' => 'details',
      '#title' => $this->t('Local edits and local fields'),
      '#open' => TRUE,
    ];
    $form['conflicts']['on_event'] = [
      '#type' => 'radios',
      '#title' => $this->t('When the origin updates an item edited here'),
      '#options' => [
        'overwrite' => $this->t("Save the origin's version as a new revision, keep the edit in the one before, and flag the item for review"),
        'draft' => $this->t("Save the origin's version as a draft and leave the edit live (needs Content Moderation; otherwise as above)"),
        'reject' => $this->t('Keep the edit and refuse the update until someone resolves the conflict'),
      ],
      '#default_value' => $config->get('conflicts.on_event') ?? 'overwrite',
      '#description' => $this->t('Pulls never replace local edits without someone accepting the origin version.'),
    ];
    $form['conflicts']['local_only'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Local-only fields'),
      '#description' => $this->t('Fields this site keeps for itself: never written by sync and not compared. One bundle per line, as <code>entity_type.bundle: field_one, field_two</code>. Mappings have their own.'),
      '#default_value' => $this->localOnlyText($config->get('local_only') ?? []),
    ];
    $form['conflicts']['follow_deletes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Delete detached items when the origin deletes them'),
      '#description' => $this->t("Otherwise a detached item stays, as this site's own content."),
      '#default_value' => (bool) $config->get('detach.follow_deletes'),
    ];

    $form['languages'] = [
      '#type' => 'details',
      '#title' => $this->t('Languages'),
      '#open' => TRUE,
      '#description' => $this->t('Which languages are received is set on the subscription page.'),
    ];
    $form['languages']['map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Language code mapping'),
      '#description' => $this->t("Origin languages kept under another code here, one per line as <code>origin|local</code>, such as <code>zh-hans|zh-cn</code>. Hashes always use the origin's codes."),
      '#default_value' => implode("\n", array_map(fn ($origin, $local) => "$origin|$local", array_keys($this->languageMap->all()), $this->languageMap->all())),
    ];
    $form['languages']['move'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Move existing translations to the new codes'),
      '#description' => $this->t('For mappings added or changed here: translations already received under the old code move to the new one, a chunk at a time on cron, before events use it.'),
      '#default_value' => TRUE,
    ];
    $form['languages']['unknown'] = [
      '#type' => 'radios',
      '#title' => $this->t('A received language this site does not have'),
      '#options' => [
        'skip' => $this->t('Skip that translation and flag the item'),
        'reject' => $this->t('Refuse the item'),
      ],
      '#default_value' => $config->get('languages.unknown') ?? 'skip',
    ];
    $form['languages']['removed'] = [
      '#type' => 'radios',
      '#title' => $this->t('Translations in a language the origin removes'),
      '#options' => [
        'keep' => $this->t('Keep them as local translations'),
        'delete' => $this->t('Delete them'),
      ],
      '#default_value' => $config->get('languages.removed') ?? 'keep',
      '#description' => $this->t('When this site stops receiving a language, the subscription page asks.'),
    ];

    $states = $this->moderationStates();
    $form['moderation'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Moderation'),
      '#open' => $states !== [],
      '#access' => $states !== [],
    ];
    $form['moderation']['target_state'] = [
      '#type' => 'select',
      '#title' => $this->t('State for synced content'),
      '#options' => ['' => $this->t("The origin's state, per translation")] + $states,
      '#default_value' => $config->get('moderation.target_state') ?? '',
      '#description' => $this->t("A state of this site's own takes moderation state, and whether the item is published, out of the comparison with the origin."),
    ];

    $form['processing'] = [
      '#type' => 'details',
      '#title' => $this->t('Processing and retries'),
    ];
    $form['processing']['webhook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint URL to give the origin'),
      '#description' => $this->t('Only when the site cannot work out its own address, such as behind a proxy. HTTPS.'),
      '#default_value' => $config->get('webhook_url'),
    ];
    $numbers = [
      'processing.auto_backlog' => [
        $this->t('Automatic mode: queue while this many events wait'),
        $this->t('0 to ignore the backlog.'),
      ],
      'processing.lock_wait' => [$this->t('Seconds to wait for an item another process is writing'), NULL],
      'processing.status_batch_limit' => [$this->t('Most events the origin may ask about at once'), NULL],
      'failures.transient_retry_limit' => [
        $this->t('Retries of a temporary failure before it counts as permanent'),
        NULL,
      ],
      'failures.retry_base' => [$this->t('First retry delay, in seconds'), NULL],
      'failures.retry_max' => [$this->t('Longest retry delay, in seconds'), NULL],
      'processed_retention_days' => [
        $this->t('Days to remember handled events'),
        $this->t('Replays of older events are applied again.'),
      ],
    ];
    foreach ($numbers as $key => [$title, $description]) {
      $form['processing'][str_replace('.', '__', $key)] = [
        '#type' => 'number',
        '#title' => $title,
        '#description' => $description,
        '#min' => 0,
        '#default_value' => $config->get($key),
      ];
    }
    $form['processing']['processing__auto_max_load'] = [
      '#type' => 'number',
      '#title' => $this->t('Automatic mode: queue while the one-minute load average is at least'),
      '#description' => $this->t('0 to ignore the load.'),
      '#min' => 0,
      '#step' => 0.1,
      '#default_value' => $config->get('processing.auto_max_load'),
    ];

    $form['files'] = [
      '#type' => 'details',
      '#title' => $this->t('Files'),
    ];
    $form['files']['pending'] = [
      '#type' => 'radios',
      '#title' => $this->t('Content whose files have not arrived'),
      '#options' => [
        'publish' => $this->t('Publish it as received'),
        'hold' => $this->t('Hold it unpublished until they arrive'),
      ],
      '#default_value' => $config->get('files.pending') ?? 'publish',
    ];
    $form['files']['links'] = [
      '#type' => 'radios',
      '#title' => $this->t('Links to files in text'),
      '#options' => [
        'local' => $this->t("Point at this site's copies"),
        'origin' => $this->t("Point at the origin's"),
      ],
      '#default_value' => $config->get('files.links') ?? 'local',
    ];
    $form['files']['fetch_on_miss'] = [
      '#type' => 'radios',
      '#title' => $this->t('A synced file requested here and missing'),
      '#options' => [
        'off' => $this->t('Not found, as usual'),
        'redirect' => $this->t("Redirect to the origin's copy and fetch it"),
        'placeholder' => $this->t('Show a placeholder and fetch it'),
      ],
      '#default_value' => $config->get('files.fetch_on_miss') ?? 'redirect',
    ];
    foreach ([
      'files.max_attempts' => $this->t('Download attempts before a file is marked as failed'),
      'files.retry_base' => $this->t('First download retry delay, in seconds'),
      'files.timeout' => $this->t('Download timeout, in seconds'),
      'files.verify_interval' => $this->t('Seconds between checks that every synced file is here and intact (0 to disable)'),
    ] as $key => $title) {
      $form['files'][str_replace('.', '__', $key)] = [
        '#type' => 'number',
        '#title' => $title,
        '#min' => 0,
        '#default_value' => $config->get($key),
      ];
    }

    $form['reconcile'] = [
      '#type' => 'details',
      '#title' => $this->t('Reconciliation'),
    ];
    $form['reconcile']['interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds between scheduled runs'),
      '#description' => $this->t('0 to run only on demand. Nightly is 86400.'),
      '#min' => 0,
      '#default_value' => $config->get('reconcile.interval'),
    ];
    $form['reconcile']['max_items'] = [
      '#type' => 'number',
      '#title' => $this->t('Items a run acts on itself'),
      '#description' => $this->t('The rest wait in a queue that cron works through at the same number per run.'),
      '#min' => 1,
      '#default_value' => $config->get('reconcile.max_items'),
    ];

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Origin addresses'),
    ];
    $form['security']['origin_allowed_ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Addresses the origin may post from'),
      '#description' => $this->t('One address or range per line. Messages from anywhere else are refused. Leave empty to accept any address; every message is signed either way.'),
      '#default_value' => implode("\n", $config->get('origin_allowed_ips') ?? []),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $form_state->set('local_only', $this->parseLocalOnly((string) $form_state->getValue('local_only')));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('local_only', $e->getMessage());
    }
    $map = [];
    foreach (array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('map')))) as $line) {
      [$origin, $local] = array_map('trim', explode('|', $line, 2)) + [1 => ''];
      if ($origin === '' || $local === '') {
        $form_state->setErrorByName('map', $this->t('Write each mapping as origin|local.'));
        break;
      }
      if (!$this->languageMap->exists($local)) {
        $form_state->setErrorByName('map', $this->t('Language %langcode does not exist on this site.', ['%langcode' => $local]));
      }
      $map[] = ['origin' => $origin, 'local' => $local];
    }
    $form_state->set('map', $map);
    try {
      $form_state->set('origin_allowed_ips', IpAllowlist::parse((string) $form_state->getValue('origin_allowed_ips')));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('origin_allowed_ips', $e->getMessage());
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $before = $this->languageMap->all();
    $config = $this->config('content_relay_replica.settings')
      ->set('conflicts.on_event', $form_state->getValue('on_event'))
      ->set('local_only', $form_state->get('local_only'))
      ->set('detach.follow_deletes', (bool) $form_state->getValue('follow_deletes'))
      ->set('languages.map', $form_state->get('map'))
      ->set('languages.unknown', $form_state->getValue('unknown'))
      ->set('languages.removed', $form_state->getValue('removed'))
      ->set('webhook_url', (string) $form_state->getValue('webhook_url'))
      ->set('files.pending', $form_state->getValue('pending'))
      ->set('files.links', $form_state->getValue('links'))
      ->set('files.fetch_on_miss', $form_state->getValue('fetch_on_miss'))
      ->set('processing.auto_max_load', (float) $form_state->getValue('processing__auto_max_load'))
      ->set('reconcile.interval', (int) $form_state->getValue('interval'))
      ->set('reconcile.max_items', max(1, (int) $form_state->getValue('max_items')))
      ->set('origin_allowed_ips', $form_state->get('origin_allowed_ips'));
    if ($form_state->hasValue('target_state')) {
      $config->set('moderation.target_state', (string) $form_state->getValue('target_state'));
    }
    foreach ($form_state->getValues() as $key => $value) {
      if (str_contains((string) $key, '__') && $key !== 'processing__auto_max_load') {
        $config->set(str_replace('__', '.', (string) $key), (int) $value);
      }
    }
    $config->save();

    // Translations follow a changed mapping when asked to.
    if ($form_state->getValue('move')) {
      foreach ($this->languageMap->all() as $origin => $local) {
        $previous = $before[$origin] ?? $origin;
        if ($previous !== $local) {
          $this->languages->move($previous, $local);
          $this->messenger()->addStatus($this->t('Translations in %from move to %to on the next cron runs.', [
            '%from' => $previous,
            '%to' => $local,
          ]));
        }
      }
      foreach (array_diff_key($before, $this->languageMap->all()) as $origin => $local) {
        $this->languages->move($local, $origin);
      }
    }
    parent::submitForm($form, $form_state);
  }

  /**
   * Local-only fields as text.
   */
  protected function localOnlyText(array $entries): string {
    return implode("\n", array_map(fn (array $entry) => sprintf('%s.%s: %s', $entry['entity_type'], $entry['bundle'], implode(', ', $entry['fields'])), $entries));
  }

  /**
   * Local-only fields from text.
   *
   * @throws \InvalidArgumentException
   *   When a line is malformed.
   */
  protected function parseLocalOnly(string $text): array {
    $entries = [];
    foreach (array_filter(array_map('trim', explode("\n", $text))) as $line) {
      if (!preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)\s*:\s*(.+)$/', $line, $m)) {
        throw new \InvalidArgumentException(sprintf('"%s" is not entity_type.bundle: field, field.', $line));
      }
      $entries[] = [
        'entity_type' => $m[1],
        'bundle' => $m[2],
        'fields' => array_values(array_filter(array_map('trim', explode(',', $m[3])))),
      ];
    }
    return $entries;
  }

  /**
   * Moderation states of every workflow, when Content Moderation is on.
   *
   * @return array<string, string>
   *   Labels by state ID.
   */
  protected function moderationStates(): array {
    if (!$this->entityTypeManager->hasDefinition('workflow')) {
      return [];
    }
    $states = [];
    foreach ($this->entityTypeManager->getStorage('workflow')->loadByProperties(['type' => 'content_moderation']) as $workflow) {
      foreach ($workflow->getTypePlugin()->getStates() as $id => $state) {
        $states[$id] = $state->label();
      }
    }
    return $states;
  }

}
