<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Report;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Views data for the replica's sync state table.
 */
final class SyncStateViewsData {

  use StringTranslationTrait;

  public function __construct(
    private readonly SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * The data, as hook_views_data() returns it.
   */
  public function getViewsData(): array {
    $table = SyncStateStore::TABLE;
    $data[$table]['table'] = [
      'group' => $this->t('Content Relay sync state'),
      'provider' => 'content_relay_replica',
      'base' => [
        'field' => 'uuid',
        'title' => $this->t('Content Relay synced items'),
        'help' => $this->t('Items this replica received from its origin.'),
        'weight' => 10,
      ],
    ];
    $data[$table]['content_relay_item'] = [
      'title' => $this->t('Item'),
      'help' => $this->t("The local item's label, linked."),
      'field' => ['id' => 'content_relay_item', 'real field' => 'uuid', 'additional fields' => ['entity_type']],
    ];
    $data[$table]['content_relay_bulk_detach'] = [
      'title' => $this->t('Bulk detach form'),
      'help' => $this->t('Checkboxes to detach or reattach selected items.'),
      'field' => ['id' => 'content_relay_bulk_detach', 'real field' => 'uuid', 'additional fields' => ['entity_type']],
    ];
    $data[$table]['entity_type'] = $this->options($this->t('Entity type'), [self::class, 'entityTypeOptions']);
    $data[$table]['uuid'] = $this->column($this->t('UUID'), 'standard', 'string');
    $data[$table]['bundle'] = $this->column($this->t('Bundle'), 'standard', 'string');
    $data[$table]['source_revision'] = $this->column($this->t('Origin revision'), 'numeric', 'numeric');
    $data[$table]['source_changed'] = $this->column($this->t('Changed on origin'), 'date', 'date', 'date');
    $data[$table]['source_hash'] = $this->column($this->t('Origin hash'), 'standard', 'string');
    $data[$table]['local_hash'] = $this->column($this->t('Local hash'), 'standard', 'string');
    $data[$table]['import_mode'] = $this->column($this->t('Import mode'), 'standard', 'string');
    $data[$table]['status'] = $this->options($this->t('Status'), [SyncStatus::class, 'labels']);
    $data[$table]['last_error'] = $this->column($this->t('Last error'), 'standard', 'string');
    $data[$table]['last_synced'] = $this->column($this->t('Last synced'), 'date', 'date', 'date');
    $data[$table]['detached_at'] = $this->column($this->t('Detached here'), 'date', 'date', 'date');
    $data[$table]['origin_detached_at'] = $this->column($this->t('Detached by origin'), 'date', 'date', 'date');
    $data[$table]['conflict_at'] = $this->column($this->t('Conflict since'), 'date', 'date', 'date');
    $data[$table]['source_default_langcode'] = $this->column($this->t('Default language on the origin'), 'standard', 'string');
    foreach ([
      'local_translations' => $this->t('Local translations'),
      'detached_translations' => $this->t('Detached translations'),
      'withheld_translations' => $this->t('Translations withheld by the origin'),
      'notes' => $this->t('Notes'),
    ] as $column => $title) {
      $data[$table][$column] = [
        'title' => $title,
        'field' => ['id' => 'content_relay_json_list'],
        'filter' => ['id' => 'string'],
      ];
    }
    $data[$table]['detached_by'] = $this->column($this->t('Detached by'), 'numeric', 'numeric');
    $data[$table]['detached_by']['relationship'] = [
      'id' => 'standard',
      'base' => 'users_field_data',
      'base field' => 'uid',
      'label' => $this->t('User who detached'),
    ];

    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $definition) {
      $base = $definition->getDataTable() ?: $definition->getBaseTable();
      if (!$base) {
        continue;
      }
      $data[$table]['entity_' . $entity_type_id] = [
        'title' => $this->t('@type', ['@type' => $definition->getLabel()]),
        'help' => $this->t('The local @type.', ['@type' => $definition->getLabel()]),
        'relationship' => [
          'id' => 'standard',
          'base' => $base,
          'base field' => (string) $definition->getKey('uuid'),
          'field' => 'uuid',
          'label' => $definition->getLabel(),
          'extra' => [['left_field' => 'entity_type', 'value' => $entity_type_id]],
        ],
      ];
    }
    return $data;
  }

  /**
   * Entity type options.
   */
  public static function entityTypeOptions(): array {
    $options = [];
    foreach (\Drupal::service('content_relay.syncable_entity_types')->getEntityTypes() as $id => $definition) {
      $options[$id] = $definition->getLabel();
    }
    return $options;
  }

  /**
   * A column with field, filter and sort plugins.
   */
  private function column(TranslatableMarkup $title, string $field, string $filter, string $sort = 'standard'): array {
    return [
      'title' => $title,
      'field' => ['id' => $field],
      'filter' => ['id' => $filter],
      'sort' => ['id' => $sort],
    ];
  }

  /**
   * A column with a fixed set of values and labels.
   */
  private function options(TranslatableMarkup $title, callable $callback): array {
    return [
      'title' => $title,
      'field' => ['id' => 'machine_name', 'options callback' => $callback],
      'filter' => ['id' => 'in_operator', 'options callback' => $callback],
      'sort' => ['id' => 'standard'],
    ];
  }

}
