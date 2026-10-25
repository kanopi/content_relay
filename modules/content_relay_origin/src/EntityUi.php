<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay_origin\Access\ItemAccessCheck;
use Drupal\content_relay_origin\Form\NotSyncableForm;
use Drupal\content_relay_origin\Routing\ItemRoutes;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Content Relay's additions to entity forms and operation links.
 */
class EntityUi {

  use StringTranslationTrait;

  public function __construct(
    protected SyncScope $scope,
    protected NotSyncableFlags $flags,
    protected ItemSyncControl $control,
    protected ManualPush $push,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Operation links for an item: push, and not syncable.
   *
   * @return array
   *   Operations, as hook_entity_operation() returns them.
   */
  public function operations(EntityInterface $entity): array {
    if (!$entity instanceof ContentEntityInterface || $entity->isNew()) {
      return [];
    }
    $operations = [];
    $parameters = [$entity->getEntityTypeId() => $entity->id()];
    if (ItemAccessCheck::forEntity($entity, 'push', $this->currentUser, $this->scope)->isAllowed()) {
      $operations['content_relay_push'] = [
        'title' => $this->t('Push now'),
        'url' => Url::fromRoute(ItemRoutes::name($entity->getEntityTypeId(), 'push'), $parameters),
        'weight' => 60,
      ];
    }
    if (ItemAccessCheck::forEntity($entity, 'not_syncable', $this->currentUser, $this->scope)->isAllowed()) {
      $flagged = $this->flags->get($entity->getEntityTypeId(), (string) $entity->uuid()) !== [];
      $operations['content_relay_not_syncable'] = [
        'title' => $flagged ? $this->t('Make syncable') : $this->t('Mark not syncable'),
        'url' => Url::fromRoute(ItemRoutes::name($entity->getEntityTypeId(), 'not_syncable'), $parameters),
        'weight' => 61,
      ];
    }
    if (ItemAccessCheck::forEntity($entity, 'validate', $this->currentUser, $this->scope)->isAllowed()) {
      $operations['content_relay_validate'] = [
        'title' => $this->t('Validate on replicas'),
        'url' => Url::fromRoute(ItemRoutes::name($entity->getEntityTypeId(), 'validate'), $parameters),
        'weight' => 62,
      ];
    }
    return $operations;
  }

  /**
   * Adds the not syncable setting to an item's edit form.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface || !in_array($form_object->getOperation(), [
      'default',
      'edit',
      'add',
    ], TRUE)) {
      return;
    }
    $entity = $form_object->getEntity();
    if (!$entity instanceof ContentEntityInterface || !$this->scope->applies($entity) || !$this->currentUser->hasPermission(ItemAccessCheck::PERMISSIONS['not_syncable']) || !isset($form['actions']['submit']['#submit'])) {
      return;
    }
    $form['content_relay'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Relay'),
      '#group' => isset($form['advanced']) ? 'advanced' : NULL,
      '#tree' => TRUE,
      '#weight' => 90,
    ] + NotSyncableForm::elements($entity, $this->flags, $this->push, $this->control);
    // #states selectors follow the tree.
    $form['content_relay']['replicas']['#states']['visible'] = [
      ':input[name="content_relay[not_syncable]"]' => ['checked' => TRUE],
      ':input[name="content_relay[scope]"]' => ['value' => 'selected'],
    ];
    foreach (['scope', 'behavior'] as $key) {
      $form['content_relay'][$key]['#states']['visible'] = [':input[name="content_relay[not_syncable]"]' => ['checked' => TRUE]];
    }
    // Before the entity is saved: a new item marked not syncable must not
    // produce a create, and an existing one must not send this save.
    $submit = &$form['actions']['submit']['#submit'];
    $position = array_search('::save', $submit, TRUE);
    array_splice($submit, $position === FALSE ? count($submit) : (int) $position, 0, [[static::class, 'submitForm']]);
  }

  /**
   * Form submit handler for the entity form.
   */
  public static function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    assert($form_object instanceof ContentEntityFormInterface);
    $entity = $form_object->getEntity();
    assert($entity instanceof ContentEntityInterface);
    \Drupal::service('content_relay_origin.entity_ui')->apply($entity, (array) $form_state->getValue('content_relay'));
  }

  /**
   * Applies the submitted not syncable setting to an item.
   *
   * For a new item this only stores the flag, before the item is saved, so
   * the save that creates it sends nothing.
   */
  public function apply(ContentEntityInterface $entity, array $values): void {
    NotSyncableForm::apply($entity, $values, $this->flags, $this->control);
  }

}
