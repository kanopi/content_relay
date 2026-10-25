<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\content_relay\Schema\SchemaDescriber;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_replica\Mapping\MappedImporter;
use Drupal\content_relay_replica\Mapping\MappingException;
use Drupal\content_relay_replica\OriginClient;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits a mapping: two columns, origin fields and fields here.
 *
 * The origin's fields come from its schema endpoint. Each can be mapped to a
 * field or property here, with transforms written as YAML, or ignored; a
 * field left unmapped is schema drift. Extra rows give fields here a value
 * from transforms alone, such as a default. Type differences without a
 * transform are pointed out. A preview builds a real origin item under the
 * mapping as it stands in the form, without saving anything.
 */
class MappingForm extends EntityForm {

  /**
   * Target value for an origin field knowingly not mapped.
   */
  protected const IGNORE = '_ignore';

  /**
   * Rows for values from transforms alone.
   */
  protected const EXTRA_ROWS = 3;

  public function __construct(
    protected OriginClient $originClient,
    protected ContractStore $contracts,
    protected SyncableEntityTypes $syncableTypes,
    protected SchemaDescriber $describer,
    protected MappedImporter $importer,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay_replica.origin_client'),
      $container->get('content_relay_replica.contracts'),
      $container->get('content_relay.syncable_entity_types'),
      $container->get('content_relay.schema_describer'),
      $container->get('content_relay_replica.mapped_importer'),
      $container->get('language_manager'),
    );
  }

  /**
   * The mapping being edited.
   */
  protected function mapping(): ContentRelayMapping {
    $mapping = $this->getEntity();
    assert($mapping instanceof ContentRelayMapping);
    return $mapping;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $mapping = $this->mapping();
    $chosen = !$mapping->isNew() || $form_state->get('chosen');

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $mapping->label(),
      '#required' => TRUE,
    ];
    $form['source'] = [
      '#type' => 'select',
      '#title' => $this->t('Origin bundle'),
      '#options' => $this->sourceOptions(),
      '#default_value' => $mapping->isNew() ? $form_state->get('source') : $mapping->getSourceEntityType() . '.' . $mapping->getSourceBundle(),
      '#required' => TRUE,
      '#disabled' => $chosen,
      '#description' => $this->t('Bundles in the current contract. Each can have one mapping.'),
    ];
    $form['target'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle here'),
      '#options' => $this->targetOptions(),
      '#default_value' => $mapping->isNew() ? $form_state->get('target') : $mapping->getTargetEntityType() . '.' . $mapping->getTargetBundle(),
      '#required' => TRUE,
      '#disabled' => $chosen,
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('A disabled mapping is ignored: the bundle is taken as it is.'),
      '#default_value' => $mapping->status(),
    ];
    if (!$chosen) {
      return $form;
    }

    [$source_type, $source_bundle] = $this->split((string) $form['source']['#default_value']);
    [$target_type, $target_bundle] = $this->split((string) $form['target']['#default_value']);
    $origin = $this->originBundle($source_type, $source_bundle, $form_state);
    $local = $this->describer->describe($target_type, $target_bundle) ?? ['fields' => []];
    $targets = $this->targetFieldOptions($local['fields']);
    $by_source = [];
    $extras = [];
    foreach ($mapping->getFieldMappings() as $field_mapping) {
      if ($field_mapping['source'] === '') {
        $extras[] = $field_mapping;
      }
      else {
        $by_source[explode('.', $field_mapping['source'], 2)[0]][] = $field_mapping;
      }
    }
    $ignored = array_flip($mapping->getIgnoredSourceFields());

    $form['fields'] = [
      '#type' => 'table',
      '#caption' => $this->t('Origin fields'),
      '#header' => [
        $this->t('Origin field'),
        $this->t('Field here'),
        $this->t('Origin property'),
        $this->t('Transforms (YAML)'),
        $this->t('Notes'),
      ],
      '#empty' => $this->t('The origin schema could not be loaded.'),
      '#tree' => TRUE,
    ];
    $source_fields = $origin['fields'] ?? [];
    foreach (array_keys($by_source) as $name) {
      $source_fields[$name] ??= ['type' => '?', 'cardinality' => 0];
    }
    foreach ($source_fields as $name => $field) {
      $existing = $by_source[$name][0] ?? NULL;
      $target_value = $existing['target'] ?? NULL;
      $form['fields'][$name] = [
        'source' => ['#markup' => sprintf('<strong>%s</strong><br><small>%s, %s</small>', htmlspecialchars($name), htmlspecialchars((string) $field['type']), $this->cardinality((int) $field['cardinality']))],
        'target' => [
          '#type' => 'select',
          '#title' => $this->t('Field here for @field', ['@field' => $name]),
          '#title_display' => 'invisible',
          '#options' => ['' => $this->t('- Not mapped -'), self::IGNORE => $this->t('- Ignore -')] + $targets,
          '#default_value' => $existing !== NULL ? $existing['target'] : (isset($ignored[$name]) ? self::IGNORE : ''),
        ],
        'source_property' => [
          '#type' => 'textfield',
          '#title' => $this->t('Origin property for @field', ['@field' => $name]),
          '#title_display' => 'invisible',
          '#size' => 10,
          '#placeholder' => $this->t('property'),
          '#default_value' => $existing !== NULL && str_contains($existing['source'], '.') ? explode('.', $existing['source'], 2)[1] : '',
          '#description' => $this->t('Optional: one property, such as "value".'),
        ],
        'process' => [
          '#type' => 'textarea',
          '#title' => $this->t('Transforms for @field', ['@field' => $name]),
          '#title_display' => 'invisible',
          '#rows' => 2,
          '#default_value' => $existing === NULL || $existing['process'] === [] ? '' : Yaml::encode($existing['process']),
        ],
        'notes' => ['#markup' => $this->compatibility($field, $target_value, $local['fields'], $existing['process'] ?? [])],
      ];
    }

    $form['extra'] = [
      '#type' => 'table',
      '#caption' => $this->t('Values from transforms alone, such as defaults'),
      '#header' => [$this->t('Field here'), $this->t('Transforms (YAML)')],
      '#tree' => TRUE,
    ];
    for ($i = 0; $i < max(self::EXTRA_ROWS, count($extras) + 1); $i++) {
      $form['extra'][$i] = [
        'target' => [
          '#type' => 'select',
          '#title' => $this->t('Field here'),
          '#title_display' => 'invisible',
          '#options' => ['' => $this->t('- None -')] + $targets,
          '#default_value' => $extras[$i]['target'] ?? '',
        ],
        'process' => [
          '#type' => 'textarea',
          '#title' => $this->t('Transforms'),
          '#title_display' => 'invisible',
          '#rows' => 2,
          '#default_value' => isset($extras[$i]) ? Yaml::encode($extras[$i]['process']) : '',
          '#placeholder' => "- plugin: default_value\n  configuration:\n    value: 'Untitled'",
        ],
      ];
    }

    $form['local_only'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields here that sync never writes'),
      '#options' => array_combine(array_keys($local['fields']), array_keys($local['fields'])),
      '#default_value' => $mapping->getLocalOnlyFields(),
    ];
    $form['unmapped_required'] = [
      '#type' => 'radios',
      '#title' => $this->t('Required fields here that are not mapped'),
      '#options' => [
        'reject' => $this->t('Refuse items: say which field is missing'),
        'allow' => $this->t('Leave it to validation'),
      ],
      '#default_value' => $mapping->rejectsUnmappedRequired() ? 'reject' : 'allow',
    ];
    $languages = ['default' => $this->t("The origin's default language")];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $languages[$langcode] = $language->getName();
    }
    $form['untranslatable_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Language for fields not translatable here'),
      '#options' => $languages,
      '#default_value' => $mapping->getUntranslatableSource(),
    ];

    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Try it on an origin item'),
      '#open' => (bool) $form_state->get('preview'),
    ];
    $form['preview']['uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('UUID of an origin item'),
      '#description' => $this->t('It is fetched from the origin and built under the mapping as it is in this form. Nothing is saved.'),
    ];
    $form['preview']['run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#submit' => ['::preview'],
      '#limit_validation_errors' => [],
    ];
    if ($result = $form_state->get('preview')) {
      $form['preview']['result'] = $result;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    if ($this->mapping()->isNew() && !$form_state->get('chosen')) {
      return [
        'continue' => [
          '#type' => 'submit',
          '#value' => $this->t('Continue'),
          '#submit' => ['::choose'],
        ],
      ];
    }
    return parent::actions($form, $form_state);
  }

  /**
   * Moves on from choosing bundles to mapping fields.
   */
  public function choose(array &$form, FormStateInterface $form_state): void {
    // The selects are disabled from now on, so their values are kept here.
    $form_state->set('source', $form_state->getValue('source'));
    $form_state->set('target', $form_state->getValue('target'));
    $form_state->set('chosen', TRUE);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($this->mapping()->isNew() && !$form_state->get('chosen')) {
      [$type, $bundle] = $this->split((string) $form_state->getValue('source'));
      if ($this->entityTypeManager->getStorage('content_relay_mapping')->load(ContentRelayMapping::idFor($type, $bundle))) {
        $form_state->setErrorByName('source', $this->t('That origin bundle has a mapping already.'));
      }
      return;
    }
    foreach ($this->rows($form_state) as $key => $row) {
      try {
        $this->process($row['process']);
      }
      catch (InvalidDataTypeException | \InvalidArgumentException $e) {
        $form_state->setErrorByName($key, $this->t('The transforms are not valid YAML: @message', ['@message' => $e->getMessage()]));
      }
    }
    if ($form_state->getErrors() !== []) {
      return;
    }
    $mapping = $this->buildEntity($form, $form_state);
    assert($mapping instanceof ContentRelayMapping);
    foreach ($this->importer->validate($mapping) as $problem) {
      $form_state->setErrorByName('fields', $problem);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof ContentRelayMapping);
    $entity->set('label', $form_state->getValue('label'));
    $entity->set('status', (bool) $form_state->getValue('status'));
    if ($entity->isNew()) {
      [$source_type, $source_bundle] = $this->split((string) ($form_state->get('source') ?? $form_state->getValue('source')));
      [$target_type, $target_bundle] = $this->split((string) ($form_state->get('target') ?? $form_state->getValue('target')));
      $entity->set('id', ContentRelayMapping::idFor($source_type, $source_bundle));
      $entity->set('source_entity_type', $source_type);
      $entity->set('source_bundle', $source_bundle);
      $entity->set('target_entity_type', $target_type);
      $entity->set('target_bundle', $target_bundle);
    }
    if (!$form_state->get('chosen') && $entity->isNew()) {
      return;
    }
    $fields = [];
    $ignored = [];
    foreach ((array) $form_state->getValue('fields') as $name => $row) {
      if (($row['target'] ?? '') === self::IGNORE) {
        $ignored[] = (string) $name;
        continue;
      }
      if (($row['target'] ?? '') === '') {
        continue;
      }
      $property = trim((string) ($row['source_property'] ?? ''));
      $fields[] = [
        'source' => $property === '' ? (string) $name : $name . '.' . $property,
        'target' => (string) $row['target'],
        'process' => $this->safeProcess((string) ($row['process'] ?? '')),
      ];
    }
    foreach ((array) $form_state->getValue('extra') as $row) {
      if (($row['target'] ?? '') !== '' && trim((string) ($row['process'] ?? '')) !== '') {
        $fields[] = [
          'source' => '',
          'target' => (string) $row['target'],
          'process' => $this->safeProcess((string) $row['process']),
        ];
      }
    }
    $entity->set('fields', $fields);
    $entity->set('ignored_source_fields', $ignored);
    $entity->set('local_only', array_values(array_filter((array) $form_state->getValue('local_only'))));
    $entity->set('unmapped_required', $form_state->getValue('unmapped_required') === 'allow' ? 'allow' : 'reject');
    $entity->set('translation', ['untranslatable_source' => (string) $form_state->getValue('untranslatable_source')]);
  }

  /**
   * Builds an origin item under the mapping in the form, without saving.
   */
  public function preview(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
    $input = $form_state->getUserInput();
    $uuid = trim((string) ($input['preview']['uuid'] ?? $input['uuid'] ?? ''));
    // A copy: the mapping in the form must not change until it is saved.
    $mapping = clone $this->mapping();
    $form_state->set('chosen', TRUE);
    $form_state->setValues($input);
    $this->copyFormValuesToEntity($mapping, $form, $form_state);
    try {
      $event = $this->originClient->item($mapping->getSourceEntityType(), $uuid);
      $context = new DenormalizationContext();
      $entity = $this->importer->build((array) $event->snapshot, $mapping, NULL, $context);
    }
    catch (SubscriptionException | MappingException | \InvalidArgumentException $e) {
      $form_state->set('preview', ['#markup' => '<p>' . $this->t('No preview: @message', ['@message' => $e->getMessage()]) . '</p>']);
      return;
    }
    $mapped = [];
    foreach ($mapping->getFieldMappings() as $field_mapping) {
      $mapped[explode('.', $field_mapping['target'], 2)[0]] = TRUE;
    }
    $rows = [];
    foreach ($entity->getFields(FALSE) as $name => $items) {
      if (!$items->isEmpty() && isset($mapped[$name])) {
        $rows[] = [$name, json_encode($items->getValue(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)];
      }
    }
    $notes = [];
    if ($context->unknownFields !== []) {
      $notes[] = $this->t('Origin fields neither mapped nor ignored: @fields.', ['@fields' => implode(', ', array_keys($context->unknownFields))]);
    }
    if ($context->missingReferences !== []) {
      $notes[] = $this->t('Referenced items not here yet: @count.', ['@count' => count($context->missingReferences)]);
    }
    $form_state->set('preview', [
      'table' => [
        '#type' => 'table',
        '#caption' => $this->t('@label, as it would be saved here', ['@label' => $event->snapshot['shared']['title'][0]['value'] ?? $uuid]),
        '#header' => [$this->t('Field'), $this->t('Value')],
        '#rows' => $rows,
        '#empty' => $this->t('No mapped field has a value.'),
      ],
      'notes' => ['#theme' => 'item_list', '#items' => $notes],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $mapping = $this->mapping();
    $this->messenger()->addStatus($this->t('Mapping %label saved. Items already synced keep their current values until they change on the origin or are re-applied.', ['%label' => $mapping->label()]));
    $form_state->setRedirect('content_relay_replica.mapping_reapply', ['content_relay_mapping' => $mapping->id()]);
    return $status;
  }

  /**
   * Notes on how an origin field fits the field it is mapped to.
   */
  protected function compatibility(array $source, ?string $target, array $local, array $process): string {
    if ($target === NULL || $target === '' || $target === self::IGNORE) {
      return '';
    }
    [$field, $property] = explode('.', $target, 2) + [1 => NULL];
    $here = $local[$field] ?? NULL;
    if ($here === NULL || $property !== NULL || $process !== []) {
      return '';
    }
    $notes = [];
    if ($here['type'] !== $source['type']) {
      $notes[] = (string) $this->t('Types differ (@from to @to): add transforms or map a property.', [
        '@from' => $source['type'],
        '@to' => $here['type'],
      ]);
    }
    if ($here['cardinality'] !== -1 && ($source['cardinality'] === -1 || $source['cardinality'] > $here['cardinality'])) {
      $notes[] = (string) $this->t('Holds fewer values here: extra values are cut.');
    }
    return implode(' ', $notes);
  }

  /**
   * Field and property options for a bundle here.
   *
   * @return array<string, string>
   *   Options.
   */
  protected function targetFieldOptions(array $fields): array {
    $options = [];
    foreach ($fields as $name => $field) {
      $options[$name] = sprintf('%s (%s)', $name, $field['type']);
      foreach ($field['properties'] as $property) {
        $options[$name . '.' . $property] = '  ' . $name . '.' . $property;
      }
    }
    return $options;
  }

  /**
   * The origin's description of a bundle, cached in the form.
   */
  protected function originBundle(string $entity_type_id, string $bundle, FormStateInterface $form_state): ?array {
    if (!$form_state->has('origin_schema')) {
      try {
        $form_state->set('origin_schema', $this->originClient->schema());
      }
      catch (SubscriptionException $e) {
        $this->messenger()->addWarning($this->t('The origin schema could not be loaded (@message); only mapped fields are shown.', ['@message' => $e->getMessage()]));
        $form_state->set('origin_schema', []);
      }
    }
    return $form_state->get('origin_schema')[$entity_type_id][$bundle] ?? NULL;
  }

  /**
   * Origin bundles in the current contract.
   *
   * @return array<string, string>
   *   Options keyed "entity_type.bundle".
   */
  protected function sourceOptions(): array {
    $options = [];
    foreach ($this->contracts->getCurrent()?->effectiveBundles() ?? [] as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        $options[$entity_type_id . '.' . $bundle] = $entity_type_id . ' › ' . $bundle;
      }
    }
    $mapping = $this->mapping();
    if (!$mapping->isNew()) {
      $options[$mapping->getSourceEntityType() . '.' . $mapping->getSourceBundle()] ??= $mapping->getSourceEntityType() . ' › ' . $mapping->getSourceBundle();
    }
    return $options;
  }

  /**
   * Bundles here.
   *
   * @return array<string, array<string, string>>
   *   Options keyed "entity_type.bundle", grouped by entity type.
   */
  protected function targetOptions(): array {
    $options = [];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $definition) {
      foreach ($this->syncableTypes->getBundles($entity_type_id) as $bundle => $label) {
        $options[(string) $definition->getLabel()][$entity_type_id . '.' . $bundle] = $label;
      }
    }
    return $options;
  }

  /**
   * The rows with transforms, keyed by form element name.
   *
   * @return array<string, array{process: string}>
   *   Rows.
   */
  protected function rows(FormStateInterface $form_state): array {
    $rows = [];
    foreach ((array) $form_state->getValue('fields') as $name => $row) {
      $rows['fields][' . $name . '][process'] = ['process' => (string) ($row['process'] ?? '')];
    }
    foreach ((array) $form_state->getValue('extra') as $i => $row) {
      $rows['extra][' . $i . '][process'] = ['process' => (string) ($row['process'] ?? '')];
    }
    return $rows;
  }

  /**
   * Parses transforms YAML into steps.
   *
   * @throws \InvalidArgumentException
   *   When it is not a list of steps with a plugin each.
   */
  protected function process(string $yaml): array {
    if (trim($yaml) === '') {
      return [];
    }
    $steps = Yaml::decode($yaml);
    if (!is_array($steps) || !array_is_list($steps)) {
      throw new \InvalidArgumentException('Write a list of steps, each with "plugin" and optional "configuration".');
    }
    foreach ($steps as $step) {
      if (!is_array($step) || !is_string($step['plugin'] ?? NULL)) {
        throw new \InvalidArgumentException('Each step needs a "plugin".');
      }
    }
    return array_map(fn (array $step) => [
      'plugin' => $step['plugin'],
      'configuration' => (array) ($step['configuration'] ?? []),
    ], $steps);
  }

  /**
   * Parses transforms YAML, or nothing when it does not parse.
   */
  protected function safeProcess(string $yaml): array {
    try {
      return $this->process($yaml);
    }
    catch (InvalidDataTypeException | \InvalidArgumentException) {
      return [];
    }
  }

  /**
   * Splits "entity_type.bundle".
   *
   * @return array{0: string, 1: string}
   *   Entity type and bundle.
   */
  protected function split(string $value): array {
    return explode('.', $value, 2) + [0 => '', 1 => ''];
  }

  /**
   * Cardinality in words.
   */
  protected function cardinality(int $cardinality): string {
    return $cardinality === -1 ? (string) $this->t('unlimited') : (string) $this->formatPlural($cardinality, '1 value', '@count values');
  }

}
