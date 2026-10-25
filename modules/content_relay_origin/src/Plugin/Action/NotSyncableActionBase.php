<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\Action;

use Drupal\content_relay_origin\Access\ItemAccessCheck;
use Drupal\content_relay_origin\ItemSyncControl;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for the bulk actions that mark items not syncable or syncable.
 */
abstract class NotSyncableActionBase extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ItemSyncControl $control,
    protected readonly SyncScope $scope,
    protected readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('content_relay_origin.item_sync_control'),
      $container->get('content_relay_origin.sync_scope'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $result = $object instanceof ContentEntityInterface
      ? ItemAccessCheck::forEntity($object, 'not_syncable', $account, $this->scope)
      : AccessResult::forbidden();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
