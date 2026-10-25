<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\content_relay\Crypto\FileCipher;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests streaming file encryption.
 *
 * @group content_relay
 */
#[Group('content_relay')]
final class FileCipherTest extends UnitTestCase {

  /**
   * Temporary files to remove.
   *
   * @var string[]
   */
  private array $paths = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->paths as $path) {
      @unlink($path);
    }
    parent::tearDown();
  }

  /**
   * A new temporary file path.
   */
  private function path(): string {
    return $this->paths[] = tempnam(sys_get_temp_dir(), 'crfc');
  }

  /**
   * Encrypts a string, returning the encrypted bytes.
   */
  private function encrypt(string $plain, string $key): string {
    $in = fopen('php://memory', 'w+');
    fwrite($in, $plain);
    rewind($in);
    $out = fopen('php://memory', 'w+');
    FileCipher::encrypt($in, $out, $key);
    rewind($out);
    return (string) stream_get_contents($out);
  }

  /**
   * Decrypts bytes.
   */
  private function decrypt(string $cipher, string $key): string {
    $in = fopen('php://memory', 'w+');
    fwrite($in, $cipher);
    rewind($in);
    $out = fopen('php://memory', 'w+');
    FileCipher::decrypt($in, $out, $key);
    rewind($out);
    return (string) stream_get_contents($out);
  }

  /**
   * Content of several sizes round-trips, around chunk boundaries too.
   */
  public function testRoundTrip(): void {
    $key = FileCipher::generateKey();
    foreach ([0, 1, FileCipher::CHUNK - 1, FileCipher::CHUNK, FileCipher::CHUNK + 1, 3 * FileCipher::CHUNK] as $size) {
      $plain = $size === 0 ? '' : random_bytes($size);
      $cipher = $this->encrypt($plain, $key);
      $this->assertStringNotContainsString($size > 16 ? substr($plain, 0, 16) : "\x00never", $cipher);
      $this->assertSame($plain, $this->decrypt($cipher, $key), "$size bytes");
    }
  }

  /**
   * Without the right key, or once altered or cut short, nothing is returned.
   */
  public function testRefusals(): void {
    $key = FileCipher::generateKey();
    $cipher = $this->encrypt(random_bytes(2 * FileCipher::CHUNK + 10), $key);
    $refused = [
      'wrong key' => [$cipher, FileCipher::generateKey()],
      'altered' => [substr_replace($cipher, chr(ord($cipher[100]) ^ 1), 100, 1), $key],
      'truncated' => [substr($cipher, 0, -50), $key],
      'final chunk dropped' => [substr($cipher, 0, 24 + 4 + FileCipher::CHUNK + 17), $key],
    ];
    foreach ($refused as $case => [$bytes, $with]) {
      try {
        $this->decrypt($bytes, $with);
        $this->fail("$case was decrypted.");
      }
      catch (\RuntimeException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * A large file is processed without being held in memory.
   */
  public function testLargeFileStreams(): void {
    $plain_path = $this->path();
    $handle = fopen($plain_path, 'w');
    $block = random_bytes(1024 * 1024);
    for ($i = 0; $i < 32; $i++) {
      fwrite($handle, $block);
    }
    fclose($handle);
    $key = FileCipher::generateKey();
    $baseline = memory_get_usage();

    $cipher_path = $this->path();
    $in = fopen($plain_path, 'r');
    $out = fopen($cipher_path, 'w');
    FileCipher::encrypt($in, $out, $key);
    fclose($in);
    fclose($out);
    $decrypted_path = $this->path();
    $in = fopen($cipher_path, 'r');
    $out = fopen($decrypted_path, 'w');
    FileCipher::decrypt($in, $out, $key);
    fclose($in);
    fclose($out);

    $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $baseline, 'Peak memory grew by less than 4 MB for a 32 MB file.');
    $this->assertSame(hash_file('sha256', $plain_path), hash_file('sha256', $decrypted_path));
    $this->assertNotSame(hash_file('sha256', $plain_path), hash_file('sha256', $cipher_path));
  }

}
