<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\File;

/**
 * Gets a file's binary from wherever the origin makes it available.
 *
 * The default transport downloads the origin's signed link over HTTPS. A site
 * whose storage is replicated by other means (object storage replication, a
 * shared volume) can replace the content_relay_replica.file_transport service
 * with one that copies or merely checks the file instead. Whatever it does,
 * the checksum is verified afterwards.
 */
interface FileTransportInterface {

  /**
   * Writes a file's binary to a local path.
   *
   * @param array $file
   *   The file's record: uuid, sha256, size, download (signed link relative
   *   to the origin URL), expires, url.
   * @param string $destination
   *   A writable local path. Write to it; never load the file into memory.
   *
   * @throws \Drupal\content_relay_replica\File\FileTransportException
   *   When the file could not be fetched.
   */
  public function fetch(array $file, string $destination): void;

}
