<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Content Moderation on synced items, when the module is enabled here.
 *
 * Synced content takes the origin's moderation state per translation by
 * default; a target state set here replaces it for every synced item, and
 * moderation state then stays out of the comparison with the origin. A
 * conflict can also be resolved by saving the origin's version as a draft,
 * leaving the edited version live.
 */
class Moderation {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ?ModerationInformationInterface $information = NULL,
  ) {}

  /**
   * Whether an item is under a moderation workflow here.
   */
  public function isModerated(ContentEntityInterface $entity): bool {
    return $this->information !== NULL && $this->information->isModeratedEntity($entity);
  }

  /**
   * The state synced content is put in, or NULL for the origin's.
   */
  public function targetState(): ?string {
    $state = (string) ($this->configFactory->get('content_relay_replica.settings')->get('moderation.target_state') ?? '');
    return $state === '' || $this->information === NULL ? NULL : $state;
  }

  /**
   * The draft state of an item's workflow, or NULL if it has none.
   */
  public function draftState(ContentEntityInterface $entity): ?string {
    $workflow = $this->information?->getWorkflowForEntity($entity);
    // The first state that is neither published nor live.
    foreach ($workflow?->getTypePlugin()->getStates() ?? [] as $state) {
      if ($state instanceof ContentModerationState && !$state->isPublishedState() && !$state->isDefaultRevisionState()) {
        return $state->id();
      }
    }
    return NULL;
  }

  /**
   * Whether a save makes the live revision, as Content Moderation decides.
   *
   * Moderation leaves this alone for saves marked as syncing, so sync
   * applies its rule itself: a new item, a state that makes the live
   * revision, or no published live revision yet.
   *
   * @return bool|null
   *   NULL when the item is not moderated.
   */
  public function isDefaultRevision(ContentEntityInterface $entity): ?bool {
    if (!$this->isModerated($entity)) {
      return NULL;
    }
    $workflow = $this->information->getWorkflowForEntity($entity);
    $state_id = (string) $entity->get('moderation_state')->value;
    if ($workflow === NULL || !$workflow->getTypePlugin()->hasState($state_id)) {
      return NULL;
    }
    $state = $workflow->getTypePlugin()->getState($state_id);
    $live = $state instanceof ContentModerationState && $state->isDefaultRevisionState();
    return $entity->isNew() || $live || !$this->information->isDefaultRevisionPublished($entity);
  }

  /**
   * Puts every translation of an item in a moderation state.
   */
  public function setState(ContentEntityInterface $entity, string $state): void {
    if (!$this->isModerated($entity)) {
      return;
    }
    foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
      $entity->getTranslation($langcode)->set('moderation_state', $state);
    }
  }

}
