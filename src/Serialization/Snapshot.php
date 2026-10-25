<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

/**
 * The structure of an entity snapshot, and a check that one is well formed.
 *
 * A snapshot is a plain array, so it survives JSON in both directions
 * unchanged and can be hashed, signed and stored without a class on the other
 * side. Its shape:
 *
 * @code
 * [
 *   'entity_type' => 'node',
 *   'bundle' => 'article',
 *   'uuid' => '…',
 *   'default_langcode' => 'en',
 *   // Untranslatable fields, once.
 *   'shared' => ['field_tags' => [['target_type' => 'taxonomy_term', 'target_uuid' => '…']]],
 *   // Translatable fields, per langcode. Every translation has a key, even
 *   // one with no translatable values. The default translation is left out
 *   // when the receiver does not get its language; default_langcode still
 *   // names it.
 *   'translations' => ['en' => ['title' => [['value' => 'Hello']]], 'fr' => […]],
 *   // Synced but not hashed: changed timestamps, file paths and the like.
 *   'metadata' => ['shared' => […], 'translations' => ['en' => ['changed' => […]]]],
 * ]
 * @endcode
 *
 * Field values are lists of items, each a map of property name to value with
 * NULL properties omitted. Empty fields are omitted altogether, so a field
 * added on the origin does not change the hash of content that never filled
 * it in; receiving a snapshot without a field therefore means "empty".
 *
 * References are never local IDs. An item referencing another entity carries
 * target_type and target_uuid; an item embedding one (a paragraph) carries the
 * embedded entity's own snapshot under the EMBEDDED key.
 */
final class Snapshot {

  /**
   * The item key under which an embedded entity's snapshot is carried.
   */
  public const EMBEDDED = 'entity';

  /**
   * The item keys a reference uses instead of a local target ID.
   */
  public const TARGET_TYPE = 'target_type';
  public const TARGET_UUID = 'target_uuid';

  /**
   * Checks that an array is a well-formed snapshot.
   *
   * This checks structure, not content: the field names and values are the
   * normalizer's business.
   *
   * @throws \InvalidArgumentException
   *   When it is not.
   */
  public static function assertValid(array $snapshot): void {
    foreach (['entity_type', 'bundle', 'uuid', 'default_langcode'] as $key) {
      if (!isset($snapshot[$key]) || !is_string($snapshot[$key]) || $snapshot[$key] === '') {
        throw new \InvalidArgumentException(sprintf('A snapshot needs a non-empty string "%s".', $key));
      }
    }
    if (!isset($snapshot['shared']) || !is_array($snapshot['shared'])) {
      throw new \InvalidArgumentException('A snapshot needs a "shared" field map.');
    }
    if (!isset($snapshot['translations']) || !is_array($snapshot['translations']) || $snapshot['translations'] === []) {
      throw new \InvalidArgumentException('A snapshot needs at least one translation.');
    }
    self::assertFields($snapshot['shared']);
    foreach ($snapshot['translations'] as $langcode => $fields) {
      if (!is_string($langcode) || !is_array($fields)) {
        throw new \InvalidArgumentException('Snapshot translations must be field maps keyed by langcode.');
      }
      self::assertFields($fields);
    }
  }

  /**
   * Langcodes in a snapshot, default first when it is there, the rest sorted.
   *
   * @return string[]
   *   The langcodes.
   */
  public static function langcodes(array $snapshot): array {
    $others = array_diff(array_map('strval', array_keys($snapshot['translations'])), [$snapshot['default_langcode']]);
    sort($others);
    return isset($snapshot['translations'][$snapshot['default_langcode']]) ? [$snapshot['default_langcode'], ...$others] : $others;
  }

  /**
   * Checks a field map: names to lists of item maps.
   */
  private static function assertFields(array $fields): void {
    foreach ($fields as $name => $items) {
      if (!is_string($name) || !is_array($items) || !array_is_list($items)) {
        throw new \InvalidArgumentException('Snapshot fields must be lists of items keyed by field name.');
      }
      foreach ($items as $item) {
        if (!is_array($item)) {
          throw new \InvalidArgumentException(sprintf('Items of field "%s" must be property maps.', $name));
        }
      }
    }
  }

}
