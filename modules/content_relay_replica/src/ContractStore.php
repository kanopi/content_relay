<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\Core\Database\Connection;

/**
 * The contract versions this replica has received and acknowledged.
 *
 * Events name the version they were built under. This replica accepts the
 * current version and the one before it, which covers events sent while a
 * change was being acknowledged; anything else is a mismatch, and the origin
 * sends its contract again.
 */
class ContractStore {

  public const TABLE = 'content_relay_contract';

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Stores a version. Storing a version already held changes nothing.
   *
   * @return bool
   *   TRUE if the version is new here.
   */
  public function save(SubscriptionContract $contract, ?int $proposed_by = NULL): bool {
    if ($this->get($contract->version()) !== NULL) {
      return FALSE;
    }
    $this->database->insert(self::TABLE)->fields([
      'version' => $contract->version(),
      'terms' => json_encode($contract->toArray(), \JSON_THROW_ON_ERROR),
      'received' => $this->time->getCurrentTime(),
      'proposed_by' => $proposed_by,
    ])->execute();
    return TRUE;
  }

  /**
   * The newest version.
   */
  public function getCurrent(): ?SubscriptionContract {
    return $this->load('DESC', 0);
  }

  /**
   * The version before the newest.
   */
  public function getPrevious(): ?SubscriptionContract {
    return $this->load('DESC', 1);
  }

  /**
   * A version.
   */
  public function get(int $version): ?SubscriptionContract {
    $terms = $this->database->select(self::TABLE, 'c')->fields('c', ['terms'])->condition('version', $version)->execute()->fetchField();
    return $terms ? SubscriptionContract::fromArray(json_decode($terms, TRUE)) : NULL;
  }

  /**
   * Whether events built under a version are accepted.
   */
  public function accepts(int $version): ?SubscriptionContract {
    foreach ([$this->getCurrent(), $this->getPrevious()] as $contract) {
      if ($contract?->version() === $version) {
        return $contract;
      }
    }
    return NULL;
  }

  /**
   * Every version, newest first, in the shape the contract renderer takes.
   *
   * @return array<int, array>
   *   History by version.
   */
  public function history(): array {
    $history = [];
    $current = $this->getCurrent()?->version();
    foreach ($this->database->select(self::TABLE, 'c')->fields('c')->orderBy('version', 'DESC')->execute() as $row) {
      $contract = SubscriptionContract::fromArray(json_decode($row->terms, TRUE));
      $history[(int) $row->version] = [
        'contract' => $contract,
        'status' => (int) $row->version === $current ? 'active' : 'superseded',
        'created' => (int) $row->received,
        'created_by' => $row->proposed_by === NULL ? NULL : (int) $row->proposed_by,
        'initiated_by' => (string) ($contract->terms['issued_by'] ?? 'origin'),
        'acknowledged' => (int) $row->received,
      ];
    }
    return $history;
  }

  /**
   * Loads the version at an offset from the newest.
   */
  protected function load(string $direction, int $offset): ?SubscriptionContract {
    $terms = $this->database->select(self::TABLE, 'c')->fields('c', ['terms'])->orderBy('version', $direction)->range($offset, 1)->execute()->fetchField();
    return $terms ? SubscriptionContract::fromArray(json_decode($terms, TRUE)) : NULL;
  }

}
