<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Report;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Views data for the status report table.
 *
 * Every column is a field, filter and sort, so the shipped view can be
 * changed freely. Each entity type that can be synced gets a relationship
 * to its own table, for columns from the item itself.
 */
final class ReportViewsData {

  use StringTranslationTrait;

  public function __construct(
    private readonly SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * The data, as hook_views_data() returns it.
   */
  public function getViewsData(): array {
    $table = ReportIndex::TABLE;
    $data[$table]['table'] = [
      'group' => $this->t('Content Relay status'),
      'provider' => 'content_relay_origin',
      'base' => [
        'field' => 'id',
        'title' => $this->t('Content Relay status'),
        'help' => $this->t('One row per synced item, translation and replica.'),
        'weight' => 10,
      ],
    ];
    $data[$table]['id'] = $this->numeric($this->t('Row ID'));
    $data[$table]['content_relay_bulk_push'] = [
      'title' => $this->t('Bulk push form'),
      'help' => $this->t('Checkboxes to push selected items.'),
      'field' => ['id' => 'content_relay_bulk_push', 'real field' => 'id'],
    ];
    $data[$table]['entity_type'] = $this->options($this->t('Entity type'), [self::class, 'entityTypeOptions']);
    $data[$table]['entity_id'] = $this->string($this->t('Entity ID'));
    $data[$table]['uuid'] = $this->string($this->t('UUID'));
    $data[$table]['bundle'] = $this->options($this->t('Bundle'), [self::class, 'bundleOptions']);
    $data[$table]['langcode'] = [
      'title' => $this->t('Language'),
      'field' => ['id' => 'language'],
      'filter' => ['id' => 'language'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'language'],
    ];
    $data[$table]['default_translation'] = $this->boolean($this->t('Default translation'));
    $data[$table]['label'] = $this->string($this->t('Label'));
    $data[$table]['replica_id'] = $this->options($this->t('Replica'), [self::class, 'replicaOptions']);
    $data[$table]['origin_revision'] = $this->numeric($this->t('Origin revision'));
    $data[$table]['origin_hash'] = $this->string($this->t('Origin hash'));
    $data[$table]['replica_hash'] = $this->string($this->t('Replica acknowledged hash'));
    $data[$table]['status'] = $this->options($this->t('Status'), [ReportIndex::class, 'statusLabels']);
    $data[$table]['translation_status'] = $this->options($this->t('Translation status'), [
      self::class,
      'translationStatusOptions',
    ]);
    $data[$table]['item_out_of_sync'] = $this->boolean($this->t('Any translation out of sync'));
    $data[$table]['not_syncable'] = $this->boolean($this->t('Not syncable'));
    $data[$table]['detached_by'] = $this->string($this->t('Detached by'));
    $data[$table]['detached_at'] = $this->date($this->t('Detached'));
    $data[$table]['last_event'] = $this->numeric($this->t('Last event log entry'));
    $data[$table]['last_attempt'] = $this->date($this->t('Last attempt'));
    $data[$table]['last_success'] = $this->date($this->t('Last success'));
    $data[$table]['last_error'] = $this->string($this->t('Last error'));
    $data[$table]['changed'] = $this->date($this->t('Changed on origin'));
    $data[$table]['content_relay_report_actions'] = [
      'title' => $this->t('Report actions'),
      'help' => $this->t('Push all matching filters, refresh status and CSV export.'),
      'area' => ['id' => 'content_relay_report_actions'],
    ];
    $data[$table]['search'] = [
      'title' => $this->t('Label or UUID'),
      'help' => $this->t('Matches either the label or the UUID.'),
      'filter' => ['id' => 'content_relay_label_or_uuid', 'real field' => 'label'],
    ];

    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $definition) {
      $base = $definition->getDataTable() ?: $definition->getBaseTable();
      if (!$base) {
        continue;
      }
      $data[$table]['entity_' . $entity_type_id] = [
        'title' => $this->t('@type', ['@type' => $definition->getLabel()]),
        'help' => $this->t('The @type the row is about.', ['@type' => $definition->getLabel()]),
        'relationship' => [
          'id' => 'standard',
          'base' => $base,
          'base field' => (string) $definition->getKey('id'),
          'field' => 'entity_id',
          'label' => $definition->getLabel(),
          'extra' => array_filter([
            ['left_field' => 'entity_type', 'value' => $entity_type_id],
            $definition->getDataTable() && $definition->hasKey('langcode') ? [
              'left_field' => 'langcode',
              'field' => (string) $definition->getKey('langcode'),
            ] : NULL,
          ]),
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
   * Published bundle options, labeled with their entity type.
   */
  public static function bundleOptions(): array {
    $syncable = \Drupal::service('content_relay.syncable_entity_types');
    $options = [];
    foreach (\Drupal::service('content_relay_origin.sync_scope')->getPublishedBundles() as $entity_type_id => $bundles) {
      $labels = $syncable->getBundles($entity_type_id);
      foreach ($bundles as $bundle) {
        $options[$bundle] = $labels[$bundle] ?? $bundle;
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Replica options.
   */
  public static function replicaOptions(): array {
    $options = [];
    foreach (\Drupal::service('content_relay_origin.replica_registry')->getAll() as $id => $replica) {
      $options[$id] = $replica->label;
    }
    return $options;
  }

  /**
   * Translation status options.
   */
  public static function translationStatusOptions(): array {
    return [
      ReportIndex::TRANSLATION_PRESENT => new TranslatableMarkup('On the replica'),
      ReportIndex::TRANSLATION_MISSING => new TranslatableMarkup('Missing translation'),
      ReportIndex::TRANSLATION_NEVER_SENT => new TranslatableMarkup('Never sent'),
    ];
  }

  /**
   * A plain string column.
   */
  private function string(TranslatableMarkup $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'string'],
    ];
  }

  /**
   * A number column.
   */
  private function numeric(TranslatableMarkup $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'numeric'],
      'filter' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'numeric'],
    ];
  }

  /**
   * A timestamp column.
   */
  private function date(TranslatableMarkup $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
    ];
  }

  /**
   * A yes/no column.
   */
  private function boolean(TranslatableMarkup $title): array {
    return [
      'title' => $title,
      'field' => ['id' => 'boolean'],
      'filter' => ['id' => 'boolean', 'label' => $title, 'type' => 'yes-no', 'use_equal' => TRUE],
      'sort' => ['id' => 'standard'],
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
      'argument' => ['id' => 'string'],
    ];
  }

}
