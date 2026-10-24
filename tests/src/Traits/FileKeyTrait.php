<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Traits;

use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\key\Entity\Key;

/**
 * Creates Content Relay keys whose value is in a file, for functional tests.
 *
 * Functional tests drive a site in another process, which does not see
 * environment variables the test sets, so keys live in a file in the test
 * site's directory instead.
 */
trait FileKeyTrait {

  /**
   * Generates a key pair and stores it as a file-backed key entity.
   */
  protected function createFileKey(string $id, KeyPurpose $purpose = KeyPurpose::Signing, string $status = KeyStore::PRIMARY): KeyPair {
    [$pair, $material] = KeyPair::generate($purpose);
    $directory = $this->siteDirectory . '/content_relay_keys';
    if (!is_dir($directory)) {
      mkdir($directory, 0775, TRUE);
    }
    $file = $directory . '/' . $id . '.key';
    file_put_contents($file, $material);
    Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => $purpose->keyTypeId(),
      'key_type_settings' => ['status' => $status],
      'key_provider' => 'file',
      'key_provider_settings' => ['file_location' => $file, 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE],
      'key_input' => 'none',
    ])->save();
    return $pair;
  }

}
