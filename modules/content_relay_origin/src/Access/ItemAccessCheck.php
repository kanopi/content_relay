<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Access;

use Drupal\content_relay_origin\SyncPermissions;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * Access to an item's push and not syncable pages.
 *
 * The item must be in a published bundle and visible to the user, and the
 * user needs the page's permission. Stopping or resuming syncing also takes
 * edit access to the item, and either the permission for every bundle or
 * the item's bundle's own.
 */
final class ItemAccessCheck implements AccessInterface {

  /**
   * Permissions by page.
   */
  public const PERMISSIONS = [
    'push' => 'push content relay items',
    'not_syncable' => 'mark content relay items not syncable',
    'validate' => 'view content relay status report',
  ];

  public function __construct(
    private readonly SyncScope $scope,
  ) {}

  /**
   * Checks access.
   */
  public function access(Route $route, RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $page = (string) $route->getRequirement('_content_relay_item');
    $entity = $route_match->getParameter((string) $route->getDefault('entity_type_id'));
    if (!isset(self::PERMISSIONS[$page]) || !$entity instanceof ContentEntityInterface) {
      return AccessResult::forbidden();
    }
    return self::forEntity($entity, $page, $account, $this->scope);
  }

  /**
   * Access to a page for an entity, for links built outside routing.
   */
  public static function forEntity(ContentEntityInterface $entity, string $page, AccountInterface $account, SyncScope $scope): AccessResultInterface {
    if ($page === 'not_syncable') {
      return self::syncPermission($entity, $account)
        ->andIf(AccessResult::allowedIf($scope->applies($entity))->addCacheTags(['config:content_relay_origin.settings']))
        ->andIf($entity->access('update', $account, TRUE));
    }
    return AccessResult::allowedIfHasPermission($account, self::PERMISSIONS[$page])
      ->andIf(AccessResult::allowedIf($scope->applies($entity))->addCacheTags(['config:content_relay_origin.settings']))
      ->andIf($entity->access('view', $account, TRUE));
  }

  /**
   * Whether a user may stop or resume syncing items of an item's bundle.
   *
   * The permission alone, for the item's own edit form, where edit access is
   * already given.
   */
  public static function syncPermission(ContentEntityInterface $entity, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermissions($account, [
      self::PERMISSIONS['not_syncable'],
      SyncPermissions::name($entity->getEntityTypeId(), $entity->bundle()),
    ], 'OR');
  }

}
