<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_replica\Mapping\MappedImporter;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Shows and resolves conflicts between local edits and the origin.
 *
 * A conflict is one of two things:
 * - The origin's version was saved over local edits, which are kept in the
 *   revision before it (conflict_revision). The item is flagged for review.
 * - The origin's version was refused, or not yet pulled, because of local
 *   edits. The local copy is as edited.
 *
 * Either way the user sees what differs, field by field, and chooses: the
 * origin's version, or the local one, which detaches the item so the origin
 * stops replacing it.
 */
class Conflicts {

  public function __construct(
    protected SyncStateStore $syncState,
    protected LocalChanges $localChanges,
    protected LocalOnlyFields $localOnly,
    protected OriginClient $originClient,
    protected Puller $puller,
    protected ItemDetacher $detacher,
    protected EntitySerializer $serializer,
    protected MappedImporter $mappedImporter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected LanguageMap $languageMap,
  ) {}

  /**
   * What differs between the local version and the origin's.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $uuid
   *   The UUID.
   *
   * @return array{local: string, origin: string, rows: list<array{field: string, langcode: string, local: string, origin: string}>}
   *   What each side is, and the fields that differ, with their values.
   *
   * @throws \InvalidArgumentException
   *   When the item is not synced or its copy is gone.
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the origin's latest version cannot be fetched.
   */
  public function diff(string $entity_type_id, string $uuid): array {
    [$state, $entity] = $this->item($entity_type_id, $uuid);
    if ($state['conflict_revision'] !== NULL && ($edit = $this->revision($entity, (int) $state['conflict_revision'])) !== NULL) {
      $local = $edit;
      $origin = $entity;
      $labels = [
        'local' => sprintf('Your edit (revision %d)', $state['conflict_revision']),
        'origin' => sprintf("The origin's version, saved over it (revision %s)", $entity->getRevisionId() ?? '-'),
      ];
    }
    else {
      $event = $this->originClient->item($entity_type_id, $uuid);
      if ($event->snapshot === NULL) {
        throw new SubscriptionException('The origin did not send the item.');
      }
      $local = $entity;
      $origin = $this->build($event->snapshot, $entity, $state);
      $labels = [
        'local' => 'On this site',
        'origin' => sprintf("The origin's latest (revision %d)", $event->sourceRevision),
      ];
    }
    return $labels + ['rows' => $this->compare($local, $origin)];
  }

  /**
   * Takes the origin's version.
   *
   * @return \Drupal\content_relay_replica\Apply\ApplyResult|null
   *   The result of pulling it, or NULL when it was already saved and only
   *   needed reviewing.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the origin cannot be asked.
   */
  public function acceptOrigin(string $entity_type_id, string $uuid): ?ApplyResult {
    $state = $this->syncState->get($entity_type_id, $uuid) ?? throw new \InvalidArgumentException('The item is not synced.');
    $this->logger->notice('@user accepted the origin version of @type @uuid.', [
      '@user' => $this->currentUser->getAccountName(),
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
    ]);
    // A draft is published by applying the origin's version properly.
    if ($state['conflict_revision'] !== NULL && ($state['notes']['conflict_mode'] ?? NULL) !== SyncApplier::CONFLICT_DRAFT) {
      $synced = $state['local_hash'] !== NULL && $state['local_hash'] === $state['expected_hash'];
      $this->syncState->save($entity_type_id, $uuid, [
        'conflict_revision' => NULL,
        'conflict_at' => NULL,
        'status' => $synced ? SyncStatus::SYNCED : SyncStatus::ERROR,
        'last_error' => $synced ? NULL : 'The local copy does not hash the same as the origin\'s.',
        'last_synced' => (int) $state['last_synced'],
      ]);
      return NULL;
    }
    return $this->puller->pull($entity_type_id, $uuid, TRUE, TRUE);
  }

  /**
   * Keeps the local version and detaches the item, or one translation.
   *
   * When the origin's version was saved over the edit, the edit is restored
   * as a new revision first: the whole item, or only that translation.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $uuid
   *   The UUID.
   * @param string|null $langcode
   *   Only this translation (origin langcode); the rest takes the origin's
   *   version and keeps syncing.
   */
  public function keepLocal(string $entity_type_id, string $uuid, ?string $langcode = NULL): void {
    [$state, $entity] = $this->item($entity_type_id, $uuid);
    $edit = $state['conflict_revision'] === NULL ? NULL : $this->revision($entity, (int) $state['conflict_revision']);
    if ($edit !== NULL) {
      $restored = $langcode === NULL ? $edit : $this->restoreTranslation($entity, $edit, $this->languageMap->toLocal($langcode));
      $restored->setNewRevision(TRUE);
      $restored->isDefaultRevision(TRUE);
      if ($restored instanceof RevisionLogInterface) {
        $restored->setRevisionLogMessage(sprintf('Restored the local edit%s from revision %d over the origin\'s version.', $langcode === NULL ? '' : ' to ' . $langcode, $state['conflict_revision']));
        $restored->setRevisionUserId((int) $this->currentUser->id());
        $restored->setRevisionCreationTime($this->time->getCurrentTime());
      }
      $restored->save();
    }
    $this->syncState->save($entity_type_id, $uuid, [
      'conflict_revision' => NULL,
      'conflict_at' => NULL,
      'last_synced' => (int) $state['last_synced'],
    ]);
    $this->logger->notice('@user kept the local version of @what @type @uuid, which is now detached.', [
      '@user' => $this->currentUser->getAccountName(),
      '@what' => $langcode === NULL ? 'all of' : 'the ' . $langcode . ' translation of',
      '@type' => $entity_type_id,
      '@uuid' => $uuid,
    ]);
    if ($langcode === NULL) {
      $this->detacher->detach($entity_type_id, $uuid);
      return;
    }
    $this->detacher->detachTranslation($entity_type_id, $uuid, $langcode);
    // The rest of the item takes the origin's version, applied afresh so its
    // hashes leave the detached translation out.
    try {
      $this->puller->pull($entity_type_id, $uuid, TRUE, TRUE);
    }
    catch (SubscriptionException $e) {
      $this->syncState->setStatus($entity_type_id, $uuid, SyncStatus::CONFLICT, sprintf('The %s translation is kept and detached; the origin\'s version of the rest could not be fetched: %s', $langcode, $e->getMessage()));
    }
  }

  /**
   * The current copy with one translation's values taken from a revision.
   */
  protected function restoreTranslation(ContentEntityInterface $current, ContentEntityInterface $edit, string $langcode): ContentEntityInterface {
    if (!$edit->hasTranslation($langcode)) {
      return $current;
    }
    $source = $edit->getTranslation($langcode);
    $target = $current->hasTranslation($langcode) ? $current->getTranslation($langcode) : $current->addTranslation($langcode);
    foreach ($source->getTranslatableFields(FALSE) as $name => $items) {
      $target->set($name, $items->getValue());
    }
    return $target->getUntranslated();
  }

  /**
   * The sync state and local copy of an item.
   *
   * @return array{0: array, 1: \Drupal\Core\Entity\ContentEntityInterface}
   *   The sync state and the copy.
   */
  protected function item(string $entity_type_id, string $uuid): array {
    $state = $this->syncState->get($entity_type_id, $uuid);
    $entity = $state === NULL ? NULL : $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    if ($state === NULL || $entity === NULL) {
      throw new \InvalidArgumentException('The item is not synced to this site.');
    }
    return [$state, $entity];
  }

  /**
   * A revision of a local copy.
   */
  protected function revision(ContentEntityInterface $entity, int $revision_id): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if (!$storage instanceof RevisionableStorageInterface) {
      return NULL;
    }
    $revision = $storage->loadRevision($revision_id);
    return $revision instanceof ContentEntityInterface ? $revision : NULL;
  }

  /**
   * The local copy as the origin's snapshot would make it, unsaved.
   */
  protected function build(array $snapshot, ContentEntityInterface $entity, array $state): ContentEntityInterface {
    $copy = clone $entity;
    $mapping = ($state['import_mode'] ?? 'as_is') === 'mapped' && $state['mapping_id'] !== NULL
      ? $this->entityTypeManager->getStorage('content_relay_mapping')->load($state['mapping_id'])
      : NULL;
    if ($mapping instanceof ContentRelayMapping) {
      return $this->mappedImporter->build($snapshot, $mapping, $copy, new DenormalizationContext());
    }
    $context = new DenormalizationContext(preserveFields: $this->localOnly->forBundle($entity->getEntityTypeId(), $entity->bundle()));
    return $this->serializer->denormalize($snapshot, $context, $copy);
  }

  /**
   * The fields that differ between two versions of an item.
   *
   * @return list<array{field: string, langcode: string, local: string, origin: string}>
   *   Rows, by field then langcode; langcode is empty for shared fields.
   */
  protected function compare(ContentEntityInterface $local, ContentEntityInterface $origin): array {
    $skip = array_flip($this->localOnly->forBundle($local->getEntityTypeId(), $local->bundle()));
    $mine = $this->flatten($this->serializer->normalize($local, new NormalizationContext()));
    $theirs = $this->flatten($this->serializer->normalize($origin, new NormalizationContext()));
    $definitions = $local->getFieldDefinitions();
    $rows = [];
    foreach (array_keys($mine + $theirs) as $key) {
      [$field, $langcode] = explode('|', (string) $key, 2);
      if (isset($skip[$field]) || ($mine[$key] ?? NULL) === ($theirs[$key] ?? NULL)) {
        continue;
      }
      $rows[] = [
        'field' => isset($definitions[$field]) ? (string) $definitions[$field]->getLabel() : $field,
        'langcode' => $langcode,
        'local' => $this->display($mine[$key] ?? []),
        'origin' => $this->display($theirs[$key] ?? []),
      ];
    }
    return $rows;
  }

  /**
   * A snapshot's hashed values, keyed "field|langcode".
   *
   * @return array<string, array>
   *   Field items.
   */
  protected function flatten(array $snapshot): array {
    $values = [];
    foreach ($snapshot['shared'] as $field => $items) {
      $values[$field . '|'] = $items;
    }
    foreach ($snapshot['translations'] as $langcode => $fields) {
      foreach ($fields as $field => $items) {
        $values[$field . '|' . $langcode] = $items;
      }
    }
    ksort($values);
    return $values;
  }

  /**
   * Field items as text a person can compare.
   */
  protected function display(array $items): string {
    if ($items === []) {
      return '';
    }
    $simple = array_filter($items, fn ($item) => is_array($item) && array_keys($item) === ['value']);
    if (count($simple) === count($items)) {
      return implode("\n", array_map(fn ($item) => (string) $item['value'], $items));
    }
    return (string) json_encode($items, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
  }

}
