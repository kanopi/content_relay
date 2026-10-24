<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;

/**
 * One-time pairing codes.
 *
 * Codes use Crockford's Base32 alphabet: no I, L, O or U, so nothing is
 * ambiguous when read aloud or typed from a screen. They are shown in groups
 * of four (K7QM-3XRP-V9TD) and accepted with or without dashes and spaces, in
 * either case, with I and L read as 1 and O as 0.
 *
 * Only a keyed hash of each code is stored, so the table is no use to someone
 * who reads it. A code works once, until it expires.
 */
class PairingCodes {

  public const TABLE = 'content_relay_pairing_code';
  public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  public const MIN_LENGTH = 8;
  public const MAX_LENGTH = 64;

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Generates and stores a code.
   *
   * @param array<string, string[]>|null $allowed_bundles
   *   Bundles a replica pairing with this code may receive, by entity type.
   *   NULL for every published bundle.
   * @param int $uid
   *   Who generated it.
   *
   * @return array{code: string, expires: int}
   *   The code, formatted for display, and when it expires. This is the only
   *   time the code exists in readable form.
   */
  public function generate(?array $allowed_bundles, int $uid): array {
    $settings = $this->configFactory->get('content_relay_origin.settings');
    $length = (int) $settings->get('pairing.code_length');
    if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
      throw new \LogicException(sprintf('Pairing codes must be %d to %d characters.', self::MIN_LENGTH, self::MAX_LENGTH));
    }
    $code = '';
    // 32 divides 256, so taking five bits of each byte is uniform.
    foreach (str_split(random_bytes($length)) as $byte) {
      $code .= self::ALPHABET[ord($byte) & 31];
    }
    $now = $this->time->getCurrentTime();
    $expires = $now + max(60, (int) $settings->get('pairing.code_lifetime'));
    $this->database->insert(self::TABLE)->fields([
      'code_hash' => $this->hash($code),
      'allowed_bundles' => $allowed_bundles === NULL ? NULL : json_encode($allowed_bundles, \JSON_THROW_ON_ERROR),
      'created_by' => $uid,
      'created' => $now,
      'expires' => $expires,
    ])->execute();
    return ['code' => self::format($code), 'expires' => $expires];
  }

  /**
   * Uses up a code.
   *
   * The code is marked used in the same statement that checks it is unused,
   * so two requests racing with one code cannot both succeed.
   *
   * @return array{allowed_bundles: array<string, string[]>|null}
   *   What the code allows.
   *
   * @throws \Drupal\content_relay_origin\PairingException
   *   When the code is unknown, expired or used.
   */
  public function consume(string $input, string $replica_id): array {
    $hash = $this->hash(self::normalize($input));
    $row = $this->database->select(self::TABLE, 'c')->fields('c')->condition('code_hash', $hash)->execute()->fetchAssoc();
    if (!$row) {
      throw new PairingException('code_invalid', 'The pairing code is not valid.');
    }
    if ($row['used'] !== NULL) {
      throw new PairingException('code_used', 'The pairing code has already been used.');
    }
    $now = $this->time->getCurrentTime();
    if ((int) $row['expires'] < $now) {
      throw new PairingException('code_expired', 'The pairing code has expired.');
    }
    $claimed = $this->database->update(self::TABLE)
      ->fields(['used' => $now, 'used_by' => $replica_id])
      ->condition('id', $row['id'])
      ->isNull('used')
      ->execute();
    if (!$claimed) {
      throw new PairingException('code_used', 'The pairing code has already been used.');
    }
    return ['allowed_bundles' => $row['allowed_bundles'] === NULL ? NULL : json_decode($row['allowed_bundles'], TRUE)];
  }

  /**
   * Removes codes that expired more than a day ago.
   */
  public function prune(): int {
    return (int) $this->database->delete(self::TABLE)->condition('expires', $this->time->getCurrentTime() - 86400, '<')->execute();
  }

  /**
   * Canonical form of typed input: no separators, upper case, I/L/O folded.
   */
  public static function normalize(string $input): string {
    $code = strtoupper(preg_replace('/[\s\-]+/', '', $input) ?? '');
    return strtr($code, ['I' => '1', 'L' => '1', 'O' => '0']);
  }

  /**
   * Groups of four, separated by dashes.
   */
  public static function format(string $code): string {
    return implode('-', str_split($code, 4));
  }

  /**
   * The stored hash of a normalized code.
   *
   * Keyed with the site's hash salt, so a table dump cannot be brute-forced
   * offline without the settings file as well.
   */
  protected function hash(string $code): string {
    return hash_hmac('sha256', $code, 'content_relay_pairing:' . Settings::getHashSalt());
  }

}
