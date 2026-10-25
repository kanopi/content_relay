<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Routing;

use Drupal\content_relay\Serialization\SyncableEntityTypes;
use Drupal\content_relay_origin\Form\NotSyncableForm;
use Drupal\content_relay_origin\Form\PushForm;
use Symfony\Component\Routing\Route;

/**
 * Push and not syncable pages for every entity type that can be synced.
 *
 * Under the entity's own page where it has one, so they can be tabs there;
 * under /admin/content/content-relay otherwise. Whether a particular item
 * can be pushed (its bundle is published, the user may) is the access
 * check's business, so publishing a bundle needs no router rebuild.
 */
final class ItemRoutes {

  public function __construct(
    private readonly SyncableEntityTypes $syncableTypes,
  ) {}

  /**
   * The route name of an item page.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $page
   *   The page: push or not_syncable.
   */
  public static function name(string $entity_type_id, string $page): string {
    return "entity.$entity_type_id.content_relay_$page";
  }

  /**
   * Builds the routes.
   *
   * @return \Symfony\Component\Routing\Route[]
   *   Routes by name.
   */
  public function routes(): array {
    $routes = [];
    foreach ($this->syncableTypes->getEntityTypes() as $entity_type_id => $definition) {
      $base = $definition->hasLinkTemplate('canonical')
        ? rtrim((string) $definition->getLinkTemplate('canonical'), '/')
        : "/admin/content/content-relay/item/$entity_type_id/{" . $entity_type_id . '}';
      $pages = [
        'push' => [PushForm::class, 'Push', 'push'],
        'not_syncable' => [NotSyncableForm::class, 'Not syncable', 'not-syncable'],
      ];
      foreach ($pages as $page => [$form, $title, $path]) {
        $routes[self::name($entity_type_id, $page)] = new Route(
          "$base/content-relay/$path",
          [
            '_form' => $form,
            '_title' => $title,
            'entity_type_id' => $entity_type_id,
          ],
          ['_content_relay_item' => $page],
          [
            '_admin_route' => TRUE,
            'parameters' => [$entity_type_id => ['type' => "entity:$entity_type_id"]],
          ],
        );
      }
    }
    return $routes;
  }

}
