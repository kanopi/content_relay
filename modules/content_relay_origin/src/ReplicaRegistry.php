<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores the replicas this origin knows.
 *
 * A table rather than configuration: replicas pair with a particular
 * environment (production), and a config import from another environment must
 * not add, remove or rekey them.
 */
class ReplicaRegistry {

  public const TABLE = 'content_relay_replica';

  /**
   * Replicas already loaded, by ID.
   *
   * @var array<string, \Drupal\content_relay_origin\Replica|null>
   */
  protected array $cache = [];

  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Adds or replaces a replica. The sequence counter is kept on replace.
   */
  public function save(Replica $replica): void {
    $now = $this->time->getCurrentTime();
    $this->database->merge(self::TABLE)
      ->key('id', $replica->id)
      ->fields([
        'label' => $replica->label,
        'endpoint' => $replica->endpoint,
        'status' => $replica->status,
        'signing_keys' => json_encode($replica->signingKeys, \JSON_THROW_ON_ERROR),
        'encryption_keys' => json_encode($replica->encryptionKeys, \JSON_THROW_ON_ERROR),
        'contract_version' => $replica->contractVersion,
        'changed' => $now,
      ])
      ->insertFields([
        'id' => $replica->id,
        'label' => $replica->label,
        'endpoint' => $replica->endpoint,
        'status' => $replica->status,
        'signing_keys' => json_encode($replica->signingKeys, \JSON_THROW_ON_ERROR),
        'encryption_keys' => json_encode($replica->encryptionKeys, \JSON_THROW_ON_ERROR),
        'contract_version' => $replica->contractVersion,
        'next_sequence' => 1,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
    unset($this->cache[$replica->id]);
  }

  /**
   * A replica by ID.
   */
  public function get(string $id): ?Replica {
    if (!array_key_exists($id, $this->cache)) {
      $row = $this->database->select(self::TABLE, 'r')->fields('r')->condition('id', $id)->execute()->fetchAssoc();
      $this->cache[$id] = $row ? $this->fromRow($row) : NULL;
    }
    return $this->cache[$id];
  }

  /**
   * All replicas, by ID.
   *
   * @return array<string, \Drupal\content_relay_origin\Replica>
   *   Replicas.
   */
  public function getAll(): array {
    $replicas = [];
    foreach ($this->database->select(self::TABLE, 'r')->fields('r')->orderBy('id')->execute()->fetchAll() as $row) {
      $row = (array) $row;
      $replicas[$row['id']] = $this->cache[$row['id']] = $this->fromRow($row);
    }
    return $replicas;
  }

  /**
   * Takes the next sequence number for a replica.
   *
   * The increment and the read happen in one transaction, so the row lock
   * taken by the update makes concurrent saves queue up rather than share a
   * number. Callers are usually already inside the entity save transaction,
   * in which case this is a savepoint within it, and a failed save gives the
   * number back.
   */
  public function takeSequence(string $id): int {
    $transaction = $this->database->startTransaction();
    $this->database->update(self::TABLE)
      ->expression('next_sequence', 'next_sequence + 1')
      ->condition('id', $id)
      ->execute();
    $next = (int) $this->database->select(self::TABLE, 'r')->fields('r', ['next_sequence'])->condition('id', $id)->execute()->fetchField();
    unset($transaction);
    if ($next < 2) {
      throw new \RuntimeException(sprintf('Unknown replica "%s".', $id));
    }
    return $next - 1;
  }

  /**
   * Records a successful delivery.
   */
  public function recordSuccess(string $id): void {
    $this->database->update(self::TABLE)->fields(['last_success' => $this->time->getCurrentTime()])->condition('id', $id)->execute();
    unset($this->cache[$id]);
  }

  /**
   * Builds a replica from a table row.
   */
  protected function fromRow(array $row): Replica {
    return new Replica(
      $row['id'],
      $row['label'],
      $row['endpoint'],
      $row['status'],
      json_decode($row['signing_keys'], TRUE) ?: [],
      json_decode($row['encryption_keys'], TRUE) ?: [],
      (int) $row['contract_version'],
      $row['last_success'] === NULL ? NULL : (int) $row['last_success'],
    );
  }

}
