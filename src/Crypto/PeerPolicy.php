<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Asks the role module's policies about a peer, with this site's own rules.
 *
 * This site's "require encryption" setting (content_relay.settings:
 * encryption.require) wins over everything: with it on, unencrypted messages
 * are refused from every peer, however they are signed.
 */
class PeerPolicy implements PeerPolicyInterface {

  /**
   * Policies from role modules.
   *
   * @var \Drupal\content_relay\Crypto\PeerPolicyInterface[]
   */
  protected array $policies = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Adds a role module's policy. Called by the service collector.
   */
  public function addPolicy(PeerPolicyInterface $policy): void {
    $this->policies[] = $policy;
  }

  /**
   * Whether this site refuses unencrypted messages altogether.
   */
  public function requiresEncryption(): bool {
    return (bool) $this->configFactory->get('content_relay.settings')->get('encryption.require');
  }

  /**
   * {@inheritdoc}
   */
  public function sendMode(string $peer): ?EncryptionMode {
    return $this->ask(__FUNCTION__, $peer) ?? EncryptionMode::None;
  }

  /**
   * {@inheritdoc}
   */
  public function peerEncryptionKey(string $peer): ?array {
    return $this->ask(__FUNCTION__, $peer);
  }

  /**
   * {@inheritdoc}
   */
  public function trustedSigningKeyIds(string $peer): ?array {
    return $this->ask(__FUNCTION__, $peer);
  }

  /**
   * {@inheritdoc}
   */
  public function allowsUnencrypted(string $peer): ?bool {
    if ($this->requiresEncryption()) {
      return FALSE;
    }
    return $this->ask(__FUNCTION__, $peer) ?? TRUE;
  }

  /**
   * The first answer from the role policies.
   */
  protected function ask(string $method, string $peer): mixed {
    foreach ($this->policies as $policy) {
      $answer = $policy->{$method}($peer);
      if ($answer !== NULL) {
        return $answer;
      }
    }
    return NULL;
  }

}
