<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Key\KeyPurpose;

/**
 * Finds a replica's public keys in the registry.
 */
class ReplicaPublicKeyResolver implements PublicKeyResolverInterface {

  public function __construct(
    protected ReplicaRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string {
    $replica = $this->registry->get($site_id);
    if (!$replica || !$replica->receivesEvents()) {
      return NULL;
    }
    $keys = $purpose === KeyPurpose::Signing ? $replica->signingKeys : $replica->encryptionKeys;
    $key = $keys[$key_id] ?? NULL;
    if (!$key || $key['status'] === 'retired') {
      return NULL;
    }
    return base64_decode($key['public_key'], TRUE) ?: NULL;
  }

}
