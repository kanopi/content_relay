<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\ContentRelay\Transform;

use Drupal\content_relay\Serialization\Snapshot;
use Drupal\content_relay_replica\Attribute\ContentRelayTransform;
use Drupal\content_relay_replica\Mapping\MappingException;
use Drupal\content_relay_replica\Mapping\TransformBase;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds the local term for each referenced term, by UUID.
 *
 * Takes reference items (or bare UUIDs) and returns items with the local term
 * ID, ready to write. Configuration: vocabulary, only accept terms of this
 * vocabulary (optional); missing, what to do when a term is not here: "skip"
 * (default) leaves it out, "reject" refuses the item.
 */
#[ContentRelayTransform(
  id: 'taxonomy_lookup',
  label: new TranslatableMarkup('Taxonomy lookup by UUID'),
  description: new TranslatableMarkup('Reference the local term with the same UUID.'),
)]
class TaxonomyLookup extends TransformBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function transform(array $values, TransformContext $context): array {
    $uuids = [];
    foreach ($values as $value) {
      $uuid = is_array($value) ? ($value[Snapshot::TARGET_UUID] ?? NULL) : $value;
      if (is_string($uuid) && $uuid !== '') {
        $uuids[] = $uuid;
      }
    }
    if ($uuids === []) {
      return [];
    }
    $properties = ['uuid' => $uuids];
    if (!empty($this->configuration['vocabulary'])) {
      $properties['vid'] = $this->configuration['vocabulary'];
    }
    $found = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties($properties) as $term) {
      $found[$term->uuid()] = $term->id();
    }
    $items = [];
    foreach ($uuids as $uuid) {
      if (isset($found[$uuid])) {
        $items[] = ['target_id' => $found[$uuid]];
      }
      elseif (($this->configuration['missing'] ?? 'skip') === 'reject') {
        throw new MappingException(sprintf('Term %s for %s is not on this site.', $uuid, $context->targetField));
      }
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfiguration(): array {
    $missing = $this->configuration['missing'] ?? 'skip';
    return in_array($missing, ['skip', 'reject'], TRUE) ? [] : ['"missing" must be skip or reject.'];
  }

}
