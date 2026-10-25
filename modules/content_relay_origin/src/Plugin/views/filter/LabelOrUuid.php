<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\StringFilter;

/**
 * Matches rows whose label contains the value or whose UUID is the value.
 */
#[ViewsFilter('content_relay_label_or_uuid')]
final class LabelOrUuid extends StringFilter {

  /**
   * {@inheritdoc}
   */
  public function operators() {
    return array_intersect_key(parent::operators(), ['contains' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $value = trim((string) $this->value);
    if ($value === '') {
      return;
    }
    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $group = $query->setWhereGroup('OR');
    $like = '%' . $query->getConnection()->escapeLike($value) . '%';
    $query->addWhere($group, "$this->tableAlias.label", $like, 'LIKE');
    $query->addWhere($group, "$this->tableAlias.uuid", $value, '=');
  }

}
