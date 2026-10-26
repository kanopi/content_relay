<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Crypto;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\PeerPolicyInterface;
use Drupal\content_relay\Key\PeerKeys;
use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * How messages to and from the origin travel, from the current contract.
 *
 * Unencrypted messages from the origin are accepted while the current
 * contract is unencrypted, and for the transition window after a contract
 * switched encryption on: events the origin queued before it learned of the
 * change may still arrive unencrypted.
 */
class OriginPolicy implements PeerPolicyInterface {

  /**
   * State key: when the current contract switched encryption on.
   */
  public const STATE_CHANGED = 'content_relay_replica.encryption_changed';

  public function __construct(
    protected OriginPeer $origin,
    protected ContractStore $contracts,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected ActivePairing $active,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sendMode(string $peer): ?EncryptionMode {
    $pairing = $this->active->forSite($peer);
    if ($pairing === NULL) {
      return NULL;
    }
    return $this->active->run($pairing, fn () => EncryptionMode::tryFrom($this->contracts->getCurrent()?->encryption() ?? 'none') ?? EncryptionMode::None);
  }

  /**
   * {@inheritdoc}
   */
  public function peerEncryptionKey(string $peer): ?array {
    $pairing = $this->active->forSite($peer);
    $origin = $pairing === NULL ? NULL : $this->origin->get($pairing);
    return $origin === NULL ? NULL : PeerKeys::preferred($origin['encryption_keys']);
  }

  /**
   * {@inheritdoc}
   */
  public function trustedSigningKeyIds(string $peer): ?array {
    // The origin learns this site's keys when the proposal announcing them
    // is answered, before they are used, so the primary key always works.
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function allowsUnencrypted(string $peer): ?bool {
    $pairing = $this->active->forSite($peer);
    if ($pairing === NULL) {
      return NULL;
    }
    return $this->active->run($pairing, function (): bool {
      $contract = $this->contracts->getCurrent();
      if ($contract === NULL || $contract->encryption() === EncryptionMode::None->value) {
        return TRUE;
      }
      $changed = $this->state->get($this->active->stateKey(self::STATE_CHANGED));
      $window = max(0, (int) $this->configFactory->get('content_relay.settings')->get('encryption.transition_window'));
      return $changed !== NULL && $this->time->getCurrentTime() - (int) $changed <= $window;
    });
  }

  /**
   * Records that the active pairing's contract switched encryption on.
   */
  public function recordChange(): void {
    $this->state->set($this->active->stateKey(self::STATE_CHANGED), $this->time->getCurrentTime());
  }

}
