<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\content_relay\Key\KeyPurpose;

/**
 * Asks every tagged public key resolver in turn.
 */
class ChainPublicKeyResolver implements PublicKeyResolverInterface {

  /**
   * The resolvers.
   *
   * @var \Drupal\content_relay\Crypto\PublicKeyResolverInterface[]
   */
  protected array $resolvers = [];

  /**
   * Adds a resolver. Called by the service collector.
   */
  public function addResolver(PublicKeyResolverInterface $resolver): void {
    $this->resolvers[] = $resolver;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string {
    foreach ($this->resolvers as $resolver) {
      if (($key = $resolver->getPublicKey($site_id, $purpose, $key_id)) !== NULL) {
        return $key;
      }
    }
    return NULL;
  }

}
