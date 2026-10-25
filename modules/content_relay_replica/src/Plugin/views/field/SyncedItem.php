<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\views\field;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The local item a sync state row is about: its label, linked when possible.
 *
 * Works for every entity type, where a relationship needs one per type.
 */
#[ViewsField('content_relay_item')]
final class SyncedItem extends FieldPluginBase {

  /**
   * The entity repository.
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityRepository = $container->get('entity.repository');
    return $instance;
  }

  /**
   * Renders the item's label, linked to it when the user may see it.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The label.
   */
  public function render(ResultRow $values) {
    $entity_type_id = (string) $values->{$this->aliases['entity_type']};
    $uuid = (string) $values->{$this->field_alias};
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    if ($entity === NULL) {
      return $this->t('(missing)');
    }
    // Linked the way the entity label field links it.
    $this->options['alter']['make_link'] = $entity->hasLinkTemplate('canonical') && $entity->access('view');
    if ($this->options['alter']['make_link']) {
      $this->options['alter']['url'] = $entity->toUrl();
    }
    return $this->sanitizeValue((string) $entity->label());
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

}
