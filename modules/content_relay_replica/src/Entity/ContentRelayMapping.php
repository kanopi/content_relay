<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Entity;

use Drupal\content_relay_replica\Form\MappingDeleteForm;
use Drupal\content_relay_replica\Form\MappingForm;
use Drupal\content_relay_replica\MappingListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * How content of one origin bundle is imported onto this site's structure.
 *
 * Without a mapping, an origin bundle is imported as it is, onto the same
 * bundle here. With one, each item is built from the field mappings: a source
 * field or property, a target field or property, and transforms between.
 * The ID is the source entity type and bundle, so there is at most one
 * mapping per origin bundle.
 *
 * - unmapped_required: what to do when a required target field has no
 *   mapping and no default: "reject" the item (default) or "allow" and let
 *   validation decide.
 * - ignored_source_fields: origin fields knowingly not mapped. Any other
 *   unmapped origin field is schema drift.
 * - local_only: target fields sync never writes.
 * - translation.untranslatable_source: the origin language that feeds target
 *   fields that are not translatable here: "default" (the origin's default
 *   language) or a langcode.
 */
#[ConfigEntityType(
  id: 'content_relay_mapping',
  label: new TranslatableMarkup('Content Relay mapping'),
  label_collection: new TranslatableMarkup('Content Relay mappings'),
  label_singular: new TranslatableMarkup('mapping'),
  label_plural: new TranslatableMarkup('mappings'),
  config_prefix: 'mapping',
  entity_keys: ['id' => 'id', 'label' => 'label', 'status' => 'status'],
  handlers: [
    'list_builder' => MappingListBuilder::class,
    'form' => [
      'add' => MappingForm::class,
      'edit' => MappingForm::class,
      'delete' => MappingDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/config/services/content-relay/replica/mappings',
    'add-form' => '/admin/config/services/content-relay/replica/mappings/add',
    'edit-form' => '/admin/config/services/content-relay/replica/mappings/{content_relay_mapping}',
    'delete-form' => '/admin/config/services/content-relay/replica/mappings/{content_relay_mapping}/delete',
  ],
  admin_permission: 'administer content relay mappings',
  config_export: [
    'id',
    'label',
    'source_entity_type',
    'source_bundle',
    'target_entity_type',
    'target_bundle',
    'fields',
    'unmapped_required',
    'ignored_source_fields',
    'local_only',
    'translation',
  ],
)]
class ContentRelayMapping extends ConfigEntityBase {

  /**
   * The ID: source entity type and bundle.
   */
  protected ?string $id = NULL;

  /**
   * The label.
   */
  protected string $label = '';

  /**
   * The origin entity type.
   */
  protected string $source_entity_type = '';

  /**
   * The origin bundle.
   */
  protected string $source_bundle = '';

  /**
   * The entity type here.
   */
  protected string $target_entity_type = '';

  /**
   * The bundle here.
   */
  protected string $target_bundle = '';

  /**
   * Field mappings: source, target and process (transform steps).
   *
   * @var array<array{source: string, target: string, process: array<array{plugin: string, configuration?: array}>}>
   */
  protected array $fields = [];

  /**
   * What to do with required target fields that are not mapped.
   */
  protected string $unmapped_required = 'reject';

  /**
   * Origin fields knowingly not mapped.
   *
   * @var string[]
   */
  protected array $ignored_source_fields = [];

  /**
   * Target fields sync never writes.
   *
   * @var string[]
   */
  protected array $local_only = [];

  /**
   * Translation handling.
   *
   * @var array{untranslatable_source?: string}
   */
  protected array $translation = ['untranslatable_source' => 'default'];

  /**
   * The ID of the mapping for an origin bundle.
   */
  public static function idFor(string $entity_type_id, string $bundle): string {
    return $entity_type_id . '__' . $bundle;
  }

  /**
   * The origin entity type.
   */
  public function getSourceEntityType(): string {
    return $this->source_entity_type;
  }

  /**
   * The origin bundle.
   */
  public function getSourceBundle(): string {
    return $this->source_bundle;
  }

  /**
   * The entity type here.
   */
  public function getTargetEntityType(): string {
    return $this->target_entity_type;
  }

  /**
   * The bundle here.
   */
  public function getTargetBundle(): string {
    return $this->target_bundle;
  }

  /**
   * The field mappings.
   *
   * @return array<array{source: string, target: string, process: array}>
   *   Field mappings, with defaults filled in.
   */
  public function getFieldMappings(): array {
    return array_map(fn (array $field) => $field + ['source' => '', 'target' => '', 'process' => []], array_values($this->fields));
  }

  /**
   * Whether an unmapped required target field refuses the item.
   */
  public function rejectsUnmappedRequired(): bool {
    return $this->unmapped_required !== 'allow';
  }

  /**
   * Origin fields knowingly not mapped.
   *
   * @return string[]
   *   Field names.
   */
  public function getIgnoredSourceFields(): array {
    return array_values($this->ignored_source_fields);
  }

  /**
   * Target fields sync never writes.
   *
   * @return string[]
   *   Field names.
   */
  public function getLocalOnlyFields(): array {
    return array_values($this->local_only);
  }

  /**
   * The origin language feeding untranslatable target fields.
   *
   * @return string
   *   "default" or a langcode.
   */
  public function getUntranslatableSource(): string {
    return (string) ($this->translation['untranslatable_source'] ?? 'default');
  }

  /**
   * The origin fields the mapping reads.
   *
   * @return string[]
   *   Field names.
   */
  public function getMappedSourceFields(): array {
    $fields = [];
    foreach ($this->getFieldMappings() as $mapping) {
      if ($mapping['source'] !== '') {
        $fields[] = explode('.', $mapping['source'], 2)[0];
      }
      foreach ($mapping['process'] as $step) {
        foreach ((array) ($step['configuration']['sources'] ?? []) as $path) {
          $fields[] = explode('.', (string) $path, 2)[0];
        }
      }
    }
    return array_values(array_unique($fields));
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $bundle_type = $this->entityTypeManager()->getDefinition($this->target_entity_type, FALSE)?->getBundleEntityType();
    if ($bundle_type !== NULL && ($bundle = $this->entityTypeManager()->getStorage($bundle_type)->load($this->target_bundle))) {
      $this->addDependency('config', $bundle->getConfigDependencyName());
    }
    return $this;
  }

}
