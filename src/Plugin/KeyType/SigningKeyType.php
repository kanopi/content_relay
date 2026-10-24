<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\KeyType;

use Drupal\content_relay\Key\KeyPurpose;

/**
 * This site's Ed25519 key pair for signing Content Relay messages.
 *
 * The Key module discovers key types by annotation only.
 *
 * @KeyType(
 *   id = "content_relay_signing",
 *   label = @Translation("Content Relay signing key"),
 *   description = @Translation("An Ed25519 key pair this site signs Content Relay messages with."),
 *   group = "content_relay",
 *   key_value = {
 *     "plugin" = "generate"
 *   }
 * )
 */
final class SigningKeyType extends ContentRelayKeyTypeBase {

  /**
   * {@inheritdoc}
   */
  public static function purpose(): KeyPurpose {
    return KeyPurpose::Signing;
  }

}
