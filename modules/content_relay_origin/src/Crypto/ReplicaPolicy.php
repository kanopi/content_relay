<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Crypto;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\PeerPolicyInterface;
use Drupal\content_relay\Key\PeerKeys;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * How messages to and from each replica travel, from its contract.
 *
 * - Messages are sent in the encryption mode of the contract in force (the
 *   pending one before the first is acknowledged).
 * - They are signed with a key the replica's acknowledged contract lists, so
 *   a replica that has not yet learned a new key can still verify.
 * - Unencrypted messages from a replica are accepted while the contract in
 *   force or the one waiting for acknowledgement is unencrypted, and for the
 *   transition window after encryption was switched on.
 *
 * Reads the contract table directly rather than through ContractManager,
 * which signs messages and so cannot be a dependency of the signer.
 */
class ReplicaPolicy implements PeerPolicyInterface {

  /**
   * State key: when encryption was switched on, by replica ID.
   */
  public const STATE_CHANGED = 'content_relay_origin.encryption_changed';

  public function __construct(
    protected Connection $database,
    protected ReplicaRegistry $registry,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sendMode(string $peer): ?EncryptionMode {
    if ($this->registry->get($peer) === NULL) {
      return NULL;
    }
    $terms = $this->terms($peer, ContractManager::ACTIVE) ?? $this->terms($peer, ContractManager::PENDING);
    return EncryptionMode::tryFrom((string) ($terms['effective']['encryption'] ?? 'none')) ?? EncryptionMode::None;
  }

  /**
   * {@inheritdoc}
   */
  public function peerEncryptionKey(string $peer): ?array {
    $replica = $this->registry->get($peer);
    return $replica === NULL ? NULL : PeerKeys::preferred($replica->encryptionKeys);
  }

  /**
   * {@inheritdoc}
   */
  public function trustedSigningKeyIds(string $peer): ?array {
    $keys = $this->terms($peer, ContractManager::ACTIVE)['joint']['origin_keys']['signing'] ?? NULL;
    return is_array($keys) && $keys !== [] ? array_map('strval', array_keys($keys)) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function allowsUnencrypted(string $peer): ?bool {
    if ($this->registry->get($peer) === NULL) {
      return NULL;
    }
    $active = $this->terms($peer, ContractManager::ACTIVE);
    $pending = $this->terms($peer, ContractManager::PENDING);
    if ($active === NULL && $pending === NULL) {
      return TRUE;
    }
    foreach ([$active, $pending] as $terms) {
      if ($terms !== NULL && ($terms['effective']['encryption'] ?? 'none') === EncryptionMode::None->value) {
        return TRUE;
      }
    }
    $changed = $this->state->get(self::STATE_CHANGED, [])[$peer] ?? NULL;
    return $changed !== NULL && $this->time->getCurrentTime() - (int) $changed <= $this->window();
  }

  /**
   * Records that a replica's contract switched encryption on, for the window.
   */
  public function recordChange(string $replica_id): void {
    $changed = $this->state->get(self::STATE_CHANGED, []);
    $changed[$replica_id] = $this->time->getCurrentTime();
    $this->state->set(self::STATE_CHANGED, $changed);
  }

  /**
   * The transition window, in seconds.
   */
  protected function window(): int {
    return max(0, (int) $this->configFactory->get('content_relay.settings')->get('encryption.transition_window'));
  }

  /**
   * The terms of a replica's newest contract with a status.
   */
  protected function terms(string $replica_id, string $status): ?array {
    $terms = $this->database->select(ContractManager::TABLE, 'c')
      ->fields('c', ['terms'])
      ->condition('replica_id', $replica_id)
      ->condition('status', $status)
      ->orderBy('version', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return is_string($terms) ? json_decode($terms, TRUE) : NULL;
  }

}
