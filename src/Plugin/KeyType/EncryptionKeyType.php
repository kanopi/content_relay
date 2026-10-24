<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\KeyType;

use Drupal\content_relay\Key\KeyPurpose;

/**
 * This site's X25519 key pair for Content Relay encryption.
 *
 * The Key module discovers key types by annotation only.
 *
 * @KeyType(
 *   id = "content_relay_encryption",
 *   label = @Translation("Content Relay encryption key"),
 *   description = @Translation("An X25519 key pair peers encrypt Content Relay messages to."),
 *   group = "content_relay",
 *   key_value = {
 *     "plugin" = "generate"
 *   }
 * )
 */
final class EncryptionKeyType extends ContentRelayKeyTypeBase {

  /**
   * {@inheritdoc}
   */
  public static function purpose(): KeyPurpose {
    return KeyPurpose::Encryption;
  }

}
