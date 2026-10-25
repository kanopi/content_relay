<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

/**
 * Checks one synced item's hashes on both sides.
 *
 * Here: the copy is hashed again and compared with what it should hash to,
 * which catches changes made behind sync's back (in the database, by code
 * that skips entity saves) as well as edits. On the origin: the item is
 * built and hashed again, and compared with what this site last received,
 * which tells whether this site has the latest version. A local mismatch on
 * an item marked as synced marks it as an error, so the report shows it.
 */
class Validator {

  public function __construct(
    protected SyncStateStore $syncState,
    protected LocalChanges $localChanges,
    protected OriginClient $originClient,
  ) {}

  /**
   * Validates an item.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $uuid
   *   The UUID.
   *
   * @return array{local: bool, local_expected: string|null, local_actual: string|null, origin: bool|null, origin_revision: int|null, received_revision: int, differing_translations: string[], origin_error: string|null}
   *   local: the copy hashes as it should. origin: this site has the
   *   origin's latest version, NULL when the origin could not be asked.
   *
   * @throws \InvalidArgumentException
   *   When the item is not synced here.
   */
  public function validate(string $entity_type_id, string $uuid): array {
    $state = $this->syncState->get($entity_type_id, $uuid) ?? throw new \InvalidArgumentException('The item is not synced to this site.');
    ['local' => $local, 'expected' => $expected, 'actual' => $actual] = $this->checkLocal($entity_type_id, $uuid, $state);

    $result = [
      'local' => $local,
      'local_expected' => $expected,
      'local_actual' => $actual,
      'origin' => NULL,
      'origin_revision' => NULL,
      'received_revision' => (int) $state['source_revision'],
      'differing_translations' => [],
      'origin_error' => NULL,
    ];
    try {
      $event = $this->originClient->item($entity_type_id, $uuid);
    }
    catch (SubscriptionException $e) {
      $result['origin_error'] = $e->getMessage();
      return $result;
    }
    if ($event->hashes === NULL) {
      $result['origin_error'] = 'The origin sent no hashes.';
      return $result;
    }
    $received = $state['source_translation_hashes'];
    $differing = [];
    // Only the languages this site receives are compared.
    foreach ($event->hashes->translations as $langcode => $hash) {
      if (in_array((string) $langcode, $event->langcodes, TRUE) && (!isset($received[$langcode]) || !hash_equals((string) $received[$langcode], (string) $hash))) {
        $differing[] = (string) $langcode;
      }
    }
    foreach (array_keys($received) as $langcode) {
      if (!isset($event->hashes->translations[$langcode])) {
        $differing[] = (string) $langcode;
      }
    }
    $result['origin'] = $differing === [];
    $result['origin_revision'] = $event->sourceRevision;
    $result['differing_translations'] = $differing;
    return $result;
  }

  /**
   * Hashes the copy here again and compares it with what it should be.
   *
   * A mismatch on an item marked as synced marks it as an error.
   *
   * @return array{local: bool, expected: string|null, actual: string|null, present: bool}
   *   Whether it matches, the hashes compared, and whether the copy exists.
   */
  public function checkLocal(string $entity_type_id, string $uuid, array $state): array {
    $entity = $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    $actual = $entity === NULL ? NULL : $this->localChanges->hashFor($entity, $state)->entity;
    $expected = $state['expected_hash'] ?? $state['source_hash'];
    $local = $actual !== NULL && $expected !== NULL && hash_equals((string) $expected, $actual);
    if (!$local && $state['status'] === SyncStatus::SYNCED) {
      $this->syncState->setStatus($entity_type_id, $uuid, SyncStatus::ERROR, $entity === NULL
        ? 'Validation found the local copy missing.'
        : 'Validation found that the local copy does not hash as it should; it was changed here.');
    }
    return ['local' => $local, 'expected' => $expected, 'actual' => $actual, 'present' => $entity !== NULL];
  }

  /**
   * What this site has of some items, for the origin's validation.
   *
   * @param array $items
   *   Each with entity_type and uuid.
   *
   * @return array<string, array>
   *   By "entity_type:uuid": synced (FALSE when this site has no record of
   *   it), and otherwise the revision and translation hashes received, the
   *   status, whether the copy here hashes as it should and is present, and
   *   the translations detached or local here.
   */
  public function answer(array $items): array {
    $answer = [];
    foreach (array_slice($items, 0, 100) as $item) {
      $entity_type_id = (string) ($item['entity_type'] ?? '');
      $uuid = (string) ($item['uuid'] ?? '');
      $state = $this->syncState->get($entity_type_id, $uuid);
      if ($state === NULL) {
        $answer[$entity_type_id . ':' . $uuid] = ['synced' => FALSE];
        continue;
      }
      $check = $this->checkLocal($entity_type_id, $uuid, $state);
      $answer[$entity_type_id . ':' . $uuid] = [
        'synced' => TRUE,
        'revision' => (int) $state['source_revision'],
        'hash_algorithm' => (int) $state['hash_algorithm'],
        'translation_hashes' => $state['source_translation_hashes'],
        'status' => $this->syncState->get($entity_type_id, $uuid)['status'] ?? $state['status'],
        'local_ok' => $check['local'],
        'present' => $check['present'],
        'detached' => $state['detached_at'] !== NULL,
        'detached_translations' => $state['detached_translations'],
        'local_translations' => $state['local_translations'],
      ];
    }
    return $answer;
  }

}
