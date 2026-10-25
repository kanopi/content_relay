<?php

declare(strict_types=1);

namespace Drupal\content_relay\File;

/**
 * Portable references to files inside text.
 *
 * A link to a stored file, or to an image style derivative of one, travels as
 * "{cr-file:<uuid>}" or "{cr-file:<uuid>:<style>}" in place of the URL. Each
 * site turns it into its own URL for the file, and back again when hashing,
 * so the hash never depends on where either site keeps its files.
 */
final class FileToken {

  /**
   * Matches a token; groups are the UUID and the optional image style.
   */
  public const PATTERN = '/\{cr-file:([0-9a-fA-F\-]{36})(?::([a-z0-9_]+))?\}/';

  /**
   * Builds a token.
   */
  public static function build(string $uuid, ?string $style = NULL): string {
    return '{cr-file:' . $uuid . ($style === NULL ? '' : ':' . $style) . '}';
  }

  /**
   * The UUIDs of the files tokens in a text refer to.
   *
   * @return string[]
   *   UUIDs.
   */
  public static function uuids(string $text): array {
    preg_match_all(self::PATTERN, $text, $matches);
    return array_values(array_unique($matches[1]));
  }

}
