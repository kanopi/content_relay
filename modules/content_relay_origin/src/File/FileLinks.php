<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\File;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;

/**
 * Signed, expiring download links for files, each for one replica.
 *
 * The link names the file, the replica, an expiry time and, for an
 * encrypted download, the encryption key the file key derives from, signed
 * with this site's Ed25519 signing key; nothing is stored. Only this site
 * checks the signature, against its own public keys, so a link survives a key
 * rotation as long as the old key is still active.
 *
 * Links are root-relative: the replica puts them after the origin URL it
 * paired with, so they do not depend on what this site thinks its own URL
 * is when it builds them in a queue run.
 */
class FileLinks {

  /**
   * Prefix of the signed bytes, so a link signature means nothing else.
   */
  public const CONTEXT = "content_relay.file.v1\n";

  public function __construct(
    protected KeyStore $keyStore,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * A download link for a replica.
   *
   * @return array{download: string, expires: int}
   *   The root-relative link and when it expires.
   */
  public function build(FileInterface $file, string $replica_id, ?string $encryption_key_id = NULL): array {
    $expires = $this->time->getCurrentTime() + max(60, (int) ($this->configFactory->get('content_relay_origin.settings')->get('files.link_lifetime') ?: 3600));
    $key = $this->keyStore->getPrimary(KeyPurpose::Signing);
    $signature = $key->sign(self::bytes((string) $file->uuid(), $replica_id, $expires, (string) $encryption_key_id));
    $query = [
      'replica' => $replica_id,
      'expires' => $expires,
      'key' => $key->id(),
    ];
    if ($encryption_key_id !== NULL) {
      $query['ek'] = $encryption_key_id;
    }
    $query['signature'] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    $url = Url::fromRoute('content_relay_origin.file_download', ['uuid' => $file->uuid()], ['query' => $query]);
    return ['download' => $url->toString(), 'expires' => $expires];
  }

  /**
   * Checks a link's signature and expiry.
   *
   * @return string|null
   *   NULL when valid, otherwise why not: bad_signature or expired.
   */
  public function check(string $uuid, string $replica_id, int $expires, string $key_id, string $signature, string $encryption_key_id = ''): ?string {
    $entry = $this->keyStore->getPublicKeys(KeyPurpose::Signing)[$key_id] ?? NULL;
    $public = $entry === NULL ? NULL : base64_decode($entry['public_key'], TRUE);
    $raw = base64_decode(strtr($signature, '-_', '+/'), TRUE);
    if (!is_string($public) || $raw === FALSE || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES
      || !sodium_crypto_sign_verify_detached($raw, self::bytes($uuid, $replica_id, $expires, $encryption_key_id), $public)) {
      return 'bad_signature';
    }
    return $expires < $this->time->getCurrentTime() ? 'expired' : NULL;
  }

  /**
   * The signed bytes.
   */
  protected static function bytes(string $uuid, string $replica_id, int $expires, string $encryption_key_id): string {
    return self::CONTEXT . $uuid . "\n" . $replica_id . "\n" . $expires . "\n" . $encryption_key_id;
  }

}
