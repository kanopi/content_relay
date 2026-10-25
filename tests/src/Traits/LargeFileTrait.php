<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Traits;

/**
 * Large files on real disk, for kernel tests of streaming.
 *
 * Kernel tests keep public:// and temporary:// in memory, where a large file
 * cannot go. This points both at a directory in the system's temporary
 * directory instead, removed when the test ends.
 *
 * The size is CONTENT_RELAY_LARGE_FILE_MB megabytes, 500 by default, as the
 * specification's scenario asks.
 */
trait LargeFileTrait {

  /**
   * The directory the test's files are in.
   */
  protected string $largeFileDirectory;

  /**
   * The large file's size in bytes.
   */
  protected function largeFileSize(): int {
    return max(1, (int) (getenv('CONTENT_RELAY_LARGE_FILE_MB') ?: 500)) * 1024 * 1024;
  }

  /**
   * Points public:// and temporary:// at real directories.
   */
  protected function useRealFileDirectories(): void {
    $this->largeFileDirectory = sys_get_temp_dir() . '/content-relay-' . bin2hex(random_bytes(6));
    foreach (['public', 'tmp'] as $name) {
      mkdir($this->largeFileDirectory . '/' . $name, 0777, TRUE);
    }
    $this->setSetting('file_public_path', $this->largeFileDirectory . '/public');
    $this->setSetting('file_temp_path', $this->largeFileDirectory . '/tmp');
  }

  /**
   * Writes a file of random-looking bytes, a block at a time.
   */
  protected function writeLargeFile(string $path, int $bytes): void {
    $block = random_bytes(1024 * 1024);
    $handle = fopen($path, 'wb');
    for ($written = 0; $written < $bytes; $written += strlen($block)) {
      fwrite($handle, substr($block, 0, min(strlen($block), $bytes - $written)));
    }
    fclose($handle);
  }

  /**
   * Removes the test's files.
   */
  protected function removeLargeFiles(): void {
    if (!isset($this->largeFileDirectory) || !is_dir($this->largeFileDirectory)) {
      return;
    }
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->largeFileDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->largeFileDirectory);
  }

}
