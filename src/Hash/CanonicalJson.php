<?php

declare(strict_types=1);

namespace Drupal\content_relay\Hash;

/**
 * Encodes a value as JSON that every site produces byte for byte.
 *
 * This is the one serialization that both hashing and signing go through, so
 * two sites agree on the bytes before either of them runs SHA-256 or Ed25519
 * over them. Three things make it deterministic:
 *
 * - Map keys are sorted recursively, so field order, property order and the
 *   order translations were added never matter. Lists keep their order,
 *   because in a field the delta is meaningful.
 * - The encoding options are fixed. Unicode and slashes are written as is, so
 *   the bytes hashed are the UTF-8 bytes stored, and floats keep a trailing .0
 *   so 1.0 and 1 stay distinct.
 * - Strings are never trimmed, re-encoded or normalized. Text that is not
 *   valid UTF-8 is an error rather than something silently repaired, because a
 *   repair one site makes and another does not is a permanent false mismatch.
 *
 * A PHP array is a list when its keys are 0..n-1 in order. An empty array is
 * therefore always encoded as [], whether it was a map or a list on the
 * sending side; both sides decode JSON with associative arrays, so they always
 * agree on this.
 */
final class CanonicalJson {

  /**
   * The json_encode() flags. Changing these is a new hash algorithm version.
   */
  public const FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

  /**
   * Encodes a value canonically.
   *
   * @param mixed $value
   *   Scalars, NULL and arrays of them. Objects are refused: their encoding
   *   depends on the class, which the other site may not share.
   *
   * @return string
   *   The canonical JSON.
   *
   * @throws \JsonException
   *   When a string is not valid UTF-8 or a float is not finite.
   * @throws \InvalidArgumentException
   *   When the value contains an object or resource.
   */
  public static function encode(mixed $value): string {
    return json_encode(self::sort($value), self::FLAGS);
  }

  /**
   * Sorts map keys recursively and rejects values JSON cannot carry exactly.
   */
  private static function sort(mixed $value): mixed {
    if (is_array($value)) {
      if (!array_is_list($value)) {
        // SORT_STRING because PHP turns numeric string keys into integers, and
        // a mixed comparison would order "10" before "9" on one site and not
        // necessarily on another after a decode.
        ksort($value, \SORT_STRING);
      }
      foreach ($value as $key => $item) {
        $value[$key] = self::sort($item);
      }
      return $value;
    }
    if (is_object($value) || is_resource($value)) {
      throw new \InvalidArgumentException(sprintf('Canonical JSON accepts scalars and arrays only, got %s.', get_debug_type($value)));
    }
    return $value;
  }

}
