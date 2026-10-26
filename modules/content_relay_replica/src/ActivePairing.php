<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\State\StateInterface;

/**
 * Which origin pairing the current work is for.
 *
 * A replica can receive from several origins. Whatever starts work for one,
 * the event endpoint (by the sender), cron (one pairing after another), a
 * form or Drush command (by the pairing it names) or an item (by the origin
 * that owns it), runs it here; the origin, its contract and the state kept
 * for it are then that pairing's. A page for one pairing, with the pairing in
 * its path, works for it throughout. A site with one pairing needs nothing
 * chosen.
 *
 * The first pairing, "default", keeps its live state under the plain state
 * keys; other pairings under the key and their ID.
 */
class ActivePairing {

  /**
   * Pairings being worked for, innermost last.
   *
   * @var string[]
   */
  protected array $stack = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
    protected Connection $database,
    protected ?RouteMatchInterface $routeMatch = NULL,
  ) {}

  /**
   * The pairing the current work is for.
   *
   * @throws \LogicException
   *   When the site has several pairings and none was chosen.
   */
  public function id(): string {
    if (($chosen = $this->current()) !== NULL) {
      return $chosen;
    }
    $ids = $this->ids();
    if (count($ids) <= 1) {
      return $ids[0] ?? ContentRelayOriginPairing::DEFAULT;
    }
    throw new \LogicException('This site receives from several origins and nothing said which one this is for.');
  }

  /**
   * The pairing chosen for the current work, if any was.
   */
  public function current(): ?string {
    if ($this->stack !== []) {
      return end($this->stack);
    }
    $pairing = $this->routeMatch?->getRawParameter('content_relay_origin_pairing');
    return is_string($pairing) && $pairing !== '' ? $pairing : NULL;
  }

  /**
   * Runs work for a pairing.
   *
   * @param string $pairing
   *   The pairing.
   * @param callable(): T $work
   *   The work.
   *
   * @return T
   *   What the work returns.
   *
   * @template T
   */
  public function run(string $pairing, callable $work): mixed {
    $this->stack[] = $pairing;
    try {
      return $work();
    }
    finally {
      array_pop($this->stack);
    }
  }

  /**
   * Every pairing, configured or paired, sorted.
   *
   * @return string[]
   *   Pairing IDs.
   */
  public function ids(): array {
    $ids = array_map('strval', array_keys($this->entityTypeManager->getStorage('content_relay_origin_pairing')->loadMultiple()));
    // The first pairing may predate its configuration.
    if (!in_array(ContentRelayOriginPairing::DEFAULT, $ids, TRUE) && is_array($this->state->get(OriginPeer::STATE_KEY))) {
      $ids[] = ContentRelayOriginPairing::DEFAULT;
    }
    sort($ids);
    return $ids;
  }

  /**
   * The pairing with an origin, by the origin's site ID.
   */
  public function forSite(string $site_id): ?string {
    foreach ($this->ids() as $id) {
      if (($this->state->get($this->stateKey(OriginPeer::STATE_KEY, $id))['site_id'] ?? NULL) === $site_id) {
        return $id;
      }
    }
    return NULL;
  }

  /**
   * The pairing of the origin a synced item came from.
   */
  public function forItem(string $entity_type_id, string $uuid): ?string {
    $site = $this->database->select(SyncStateStore::TABLE, 's')
      ->fields('s', ['source_site'])
      ->condition('entity_type', $entity_type_id)
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchField();
    return is_string($site) ? $this->forSite($site) : NULL;
  }

  /**
   * Whether work on an item has to switch to the item's pairing first.
   *
   * @return string|null
   *   The pairing to switch to, or NULL to carry on.
   */
  public function switchFor(string $entity_type_id, string $uuid): ?string {
    $pairing = $this->forItem($entity_type_id, $uuid);
    return $pairing !== NULL && $pairing !== $this->current() ? $pairing : NULL;
  }

  /**
   * A state key for a pairing: the key itself for the default pairing.
   *
   * @param string $key
   *   The state key.
   * @param string|null $pairing
   *   The pairing; NULL for the current one.
   */
  public function stateKey(string $key, ?string $pairing = NULL): string {
    $pairing ??= $this->id();
    return $pairing === ContentRelayOriginPairing::DEFAULT ? $key : $key . ':' . $pairing;
  }

}
