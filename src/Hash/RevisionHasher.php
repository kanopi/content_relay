<?php

declare(strict_types=1);

namespace Drupal\content_relay\Hash;

use Drupal\content_relay\Serialization\Snapshot;

/**
 * Computes the canonical hashes of an entity snapshot.
 *
 * Hashing works on the snapshot array, never on a loaded entity. That is what
 * lets a replica verify an incoming payload before it touches its database,
 * and recompute the same hash after saving by normalizing what it saved: both
 * sites hash the same structure built by the same normalizer.
 *
 * What a version 1 hash covers:
 *
 * - entity type, bundle, UUID and default langcode;
 * - every synced field, with references as UUIDs (the normalizer guarantees
 *   no local ID reaches a snapshot);
 * - every translation, keyed and therefore ordered by origin langcode;
 * - embedded entities such as paragraphs, recursively, by the same rules.
 *
 * What it leaves out: the snapshot's metadata section (changed timestamps,
 * file paths and anything else a normalizer puts there because it is
 * informative but not content), and any field the caller names as local-only.
 *
 * Each translation also gets a hash of its own: its translatable fields plus
 * the untranslatable fields it shares, with embedded entities taken in that
 * same language. Updating only the French translation changes the entity hash
 * and the French hash, and no other translation's.
 */
final class RevisionHasher {

  /**
   * The canonical form produced by this class.
   *
   * Increment it whenever anything that reaches the hashed bytes changes:
   * structure, field selection or CanonicalJson's options. Keep computing the
   * old versions for as long as stored hashes may use them.
   */
  public const CURRENT_VERSION = 1;

  /**
   * The versions this class can compute.
   */
  public const SUPPORTED_VERSIONS = [1];

  /**
   * Computes the entity and per-translation hashes of a snapshot.
   *
   * @param array $snapshot
   *   A snapshot as built by an entity normalizer.
   * @param string[] $local_only_fields
   *   Top-level field names to leave out, for a replica's local-only fields.
   * @param int $version
   *   The algorithm version to compute.
   *
   * @return \Drupal\content_relay\Hash\RevisionHashes
   *   The hashes.
   *
   * @throws \InvalidArgumentException
   *   When the snapshot is malformed or the version unsupported.
   * @throws \JsonException
   *   When the snapshot holds text that is not valid UTF-8.
   */
  public function hash(array $snapshot, array $local_only_fields = [], int $version = self::CURRENT_VERSION): RevisionHashes {
    if (!in_array($version, self::SUPPORTED_VERSIONS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Hash algorithm version %d is not supported.', $version));
    }
    Snapshot::assertValid($snapshot);
    $snapshot = $this->withoutFields($snapshot, $local_only_fields);

    $translations = [];
    foreach (array_keys($snapshot['translations']) as $langcode) {
      $translations[(string) $langcode] = $this->sha256($this->translationForm($snapshot, (string) $langcode, $version));
    }
    ksort($translations, \SORT_STRING);

    return new RevisionHashes($version, $this->sha256($this->entityForm($snapshot, $version)), $translations);
  }

  /**
   * Whether a snapshot matches previously computed hashes.
   *
   * Used to check an incoming payload against the hashes the origin signed. A
   * payload altered after hashing fails here even when its signature is valid.
   */
  public function matches(array $snapshot, RevisionHashes $expected, array $local_only_fields = []): bool {
    if (!in_array($expected->algorithmVersion, self::SUPPORTED_VERSIONS, TRUE)) {
      return FALSE;
    }
    try {
      $actual = $this->hash($snapshot, $local_only_fields, $expected->algorithmVersion);
    }
    catch (\InvalidArgumentException | \JsonException) {
      return FALSE;
    }
    return hash_equals($expected->entity, $actual->entity) && $expected->translations === $actual->translations;
  }

  /**
   * The canonical structure of the whole entity.
   */
  private function entityForm(array $snapshot, int $version): array {
    $translations = [];
    foreach ($snapshot['translations'] as $langcode => $fields) {
      $translations[$langcode] = $this->fieldsForm($fields, (string) $langcode);
    }
    return [
      'v' => $version,
      'entity_type' => $snapshot['entity_type'],
      'bundle' => $snapshot['bundle'],
      'uuid' => $snapshot['uuid'],
      'default_langcode' => $snapshot['default_langcode'],
      'shared' => $this->fieldsForm($snapshot['shared'], NULL),
      'translations' => $translations,
    ];
  }

  /**
   * The canonical structure of one translation.
   */
  private function translationForm(array $snapshot, string $langcode, int $version): array {
    return [
      'v' => $version,
      'entity_type' => $snapshot['entity_type'],
      'bundle' => $snapshot['bundle'],
      'uuid' => $snapshot['uuid'],
      'langcode' => $langcode,
      'is_default' => $langcode === $snapshot['default_langcode'],
      'shared' => $this->fieldsForm($snapshot['shared'], $langcode),
      'fields' => $this->fieldsForm($snapshot['translations'][$langcode], $langcode),
    ];
  }

  /**
   * Fields with every embedded entity reduced to its canonical form.
   *
   * @param array $fields
   *   Field name => list of items.
   * @param string|null $langcode
   *   The language embedded entities are taken in for a translation hash, or
   *   NULL for the entity hash, which covers embedded entities whole.
   */
  private function fieldsForm(array $fields, ?string $langcode): array {
    foreach ($fields as $name => $items) {
      foreach ($items as $delta => $item) {
        if (isset($item[Snapshot::EMBEDDED]) && is_array($item[Snapshot::EMBEDDED])) {
          $fields[$name][$delta][Snapshot::EMBEDDED] = $this->embeddedForm($item[Snapshot::EMBEDDED], $langcode);
        }
      }
    }
    return $fields;
  }

  /**
   * The canonical structure of an embedded entity.
   */
  private function embeddedForm(array $snapshot, ?string $langcode): array {
    Snapshot::assertValid($snapshot);
    if ($langcode === NULL) {
      $form = $this->entityForm($snapshot, 0);
    }
    else {
      // An embedded entity without this translation contributes its default
      // translation, which is what the parent's translation displays.
      $own = isset($snapshot['translations'][$langcode]) ? $langcode : $snapshot['default_langcode'];
      $form = $this->translationForm($snapshot, $own, 0);
    }
    // The parent's version already covers the whole structure.
    unset($form['v']);
    return $form;
  }

  /**
   * Removes local-only fields from the top level of a snapshot.
   */
  private function withoutFields(array $snapshot, array $names): array {
    foreach ($names as $name) {
      unset($snapshot['shared'][$name]);
      foreach (array_keys($snapshot['translations']) as $langcode) {
        unset($snapshot['translations'][$langcode][$name]);
      }
    }
    return $snapshot;
  }

  /**
   * SHA-256 of a structure's canonical JSON.
   */
  private function sha256(array $form): string {
    return hash('sha256', CanonicalJson::encode($form));
  }

}
