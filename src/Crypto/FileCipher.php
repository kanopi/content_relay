<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

/**
 * Encrypts and decrypts files as streams, with a random key per file.
 *
 * Uses libsodium's secretstream (XChaCha20-Poly1305): the file is cut into
 * chunks, each authenticated, the last one marked final, so a truncated or
 * reordered file is refused. Only one chunk is in memory at a time, so file
 * size does not matter.
 *
 * Format: the secretstream header, then for each chunk a 4-byte big-endian
 * length followed by the encrypted chunk.
 */
final class FileCipher {

  /**
   * Plain bytes per chunk.
   */
  public const CHUNK = 65536;

  /**
   * A new random key, raw bytes.
   */
  public static function generateKey(): string {
    return sodium_crypto_secretstream_xchacha20poly1305_keygen();
  }

  /**
   * Encrypts a stream into another.
   *
   * @param resource $in
   *   Plain bytes, read to the end.
   * @param resource $out
   *   Receives the encrypted file.
   * @param string $key
   *   The file key, raw bytes.
   */
  public static function encrypt($in, $out, #[\SensitiveParameter] string $key): void {
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    self::write($out, $header);
    $chunk = self::read($in, self::CHUNK);
    do {
      $next = feof($in) ? '' : self::read($in, self::CHUNK);
      $final = $next === '' && feof($in);
      $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
      self::write($out, pack('N', strlen($cipher)) . $cipher);
      $chunk = $next;
    } while (!$final);
  }

  /**
   * Decrypts a stream into another.
   *
   * @param resource $in
   *   An encrypted file, read to the end.
   * @param resource $out
   *   Receives the plain bytes.
   * @param string $key
   *   The file key, raw bytes.
   *
   * @throws \RuntimeException
   *   When the file was altered, truncated or encrypted with another key.
   */
  public static function decrypt($in, $out, #[\SensitiveParameter] string $key): void {
    $header = self::read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
    if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
      throw new \RuntimeException('The encrypted file has no header.');
    }
    $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
    while (TRUE) {
      $length = self::read($in, 4);
      if (strlen($length) !== 4) {
        throw new \RuntimeException('The encrypted file ends before its final chunk.');
      }
      $size = unpack('N', $length)[1];
      if ($size > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
        throw new \RuntimeException('The encrypted file has an oversized chunk.');
      }
      $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::read($in, $size));
      if ($result === FALSE) {
        throw new \RuntimeException('The encrypted file was altered or the key is wrong.');
      }
      [$plain, $tag] = $result;
      self::write($out, $plain);
      if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
        return;
      }
    }
  }

  /**
   * Reads exactly a number of bytes, or fewer at the end of the stream.
   *
   * @param resource $in
   *   The stream.
   * @param int $length
   *   Bytes wanted.
   */
  private static function read($in, int $length): string {
    $data = '';
    while (strlen($data) < $length && !feof($in)) {
      $part = fread($in, $length - strlen($data));
      if ($part === FALSE) {
        throw new \RuntimeException('Reading the file failed.');
      }
      $data .= $part;
    }
    return $data;
  }

  /**
   * Writes all bytes.
   *
   * @param resource $out
   *   The stream.
   * @param string $data
   *   The bytes.
   */
  private static function write($out, string $data): void {
    if (fwrite($out, $data) !== strlen($data)) {
      throw new \RuntimeException('Writing the file failed.');
    }
  }

}
