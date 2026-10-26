<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Key\KeyPurpose;

/**
 * Finds a paired origin's public keys. No other site's messages are accepted.
 */
class OriginPublicKeyResolver implements PublicKeyResolverInterface {

  public function __construct(
    protected OriginPeer $origin,
    protected ActivePairing $active,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string {
    $pairing = $this->active->forSite($site_id);
    $origin = $pairing === NULL ? NULL : $this->origin->get($pairing);
    if ($origin === NULL) {
      return NULL;
    }
    $key = ($purpose === KeyPurpose::Signing ? $origin['signing_keys'] : $origin['encryption_keys'])[$key_id] ?? NULL;
    if (!is_array($key) || ($key['status'] ?? '') === 'retired') {
      return NULL;
    }
    return base64_decode((string) $key['public_key'], TRUE) ?: NULL;
  }

}
