<?php

declare(strict_types=1);

namespace Drupal\content_relay\File;

use Drupal\Core\Database\Connection;

/**
 * SHA-256 checksums of stored files, cached by size and modification time.
 *
 * A file's checksum is part of the hash of the file entity and of every item
 * that uses it, so it is needed on every normalization. Hashing a large file
 * each time would be slow; the cached value is reused while the file's size
 * and modification time are unchanged. Files are read as streams, never into
 * memory whole.
 */
class FileChecksums {

  public const TABLE = 'content_relay_file_checksum';

  /**
   * Checksums computed or read in this request, by URI.
   *
   * @var array<string, array{sha256: string, size: int, mtime: int}>
   */
  protected array $memory = [];

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * The checksum and size of the file at a URI, or NULL if it is not there.
   *
   * @return array{sha256: string, size: int}|null
   *   The checksum, hex, and the size in bytes.
   */
  public function forUri(string $uri): ?array {
    clearstatcache(TRUE, $uri);
    if (!is_file($uri)) {
      return NULL;
    }
    $size = (int) filesize($uri);
    $mtime = (int) filemtime($uri);
    $known = $this->memory[$uri] ?? NULL;
    if ($known !== NULL && $known['size'] === $size && $known['mtime'] === $mtime) {
      return ['sha256' => $known['sha256'], 'size' => $size];
    }
    $key = hash('sha256', $uri);
    $cached = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['sha256', 'size', 'mtime'])
      ->condition('uri_hash', $key)
      ->execute()
      ->fetchAssoc();
    if ($cached && (int) $cached['size'] === $size && (int) $cached['mtime'] === $mtime) {
      $sha256 = (string) $cached['sha256'];
    }
    else {
      $sha256 = hash_file('sha256', $uri);
      if ($sha256 === FALSE) {
        return NULL;
      }
      $this->database->merge(self::TABLE)
        ->keys(['uri_hash' => $key])
        ->fields(['uri' => $uri, 'sha256' => $sha256, 'size' => $size, 'mtime' => $mtime])
        ->execute();
    }
    $this->memory[$uri] = ['sha256' => $sha256, 'size' => $size, 'mtime' => $mtime];
    return ['sha256' => $sha256, 'size' => $size];
  }

  /**
   * Forgets what is known about a URI, after its file was written.
   */
  public function forget(string $uri): void {
    unset($this->memory[$uri]);
    $this->database->delete(self::TABLE)->condition('uri_hash', hash('sha256', $uri))->execute();
  }

}
