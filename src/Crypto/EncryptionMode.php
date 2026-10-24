<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

/**
 * Whether a message body travels encrypted.
 */
enum EncryptionMode: string {

  // Signed only. HTTPS is still required.
  case None = 'none';
  // Signed, then sealed to the recipient's X25519 key.
  case Sealed = 'sealed';

}
