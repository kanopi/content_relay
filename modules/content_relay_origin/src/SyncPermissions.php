<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Permissions to stop and resume syncing items, per bundle.
 *
 * One for every bundle that can be synced, whether or not it is published
 * now, so roles keep them while a bundle is taken out of sync for a while.
 * Each depends on its bundle, so deleting the bundle removes it from roles.
 * "Stop and resume syncing Content Relay items" covers every bundle.
 */
final class SyncPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected SyncableEntityTypes $syncableTypes,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_relay.syncable_entity_types'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * The permission for one bundle.
   */
  public static function name(string $entity_type_id, string $bundle): string {
    return "stop syncing $entity_type_id $bundle items";
  }

  /**
   * Permissions for every syncable bundle.
   *
   * @return array<string, array>
   *   Permission definitions.
   */
  public function permissions(): array {
    $permissions = [];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $entity_type) {
      $bundle_entity_type = $entity_type->getBundleEntityType();
      foreach ($this->syncableTypes->getBundles($entity_type_id) as $bundle => $label) {
        $permission = [
          'title' => new TranslatableMarkup('%type: Stop and resume syncing %bundle', [
            '%type' => $entity_type->getLabel(),
            '%bundle' => $label,
          ]),
          'description' => new TranslatableMarkup('Also needs edit access to the item.'),
        ];
        $bundle_entity = $bundle_entity_type === NULL ? NULL : $this->entityTypeManager->getStorage($bundle_entity_type)->load($bundle);
        if ($bundle_entity !== NULL) {
          $permission['dependencies'][$bundle_entity->getConfigDependencyKey()][] = $bundle_entity->getConfigDependencyName();
        }
        else {
          $permission['dependencies']['module'][] = $entity_type->getProvider();
        }
        $permissions[self::name($entity_type_id, (string) $bundle)] = $permission;
      }
    }
    return $permissions;
  }

}
