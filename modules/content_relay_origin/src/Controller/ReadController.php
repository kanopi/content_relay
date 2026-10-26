<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\content_relay\Schema\SchemaDescriber;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\EventBuilder;
use Drupal\content_relay_origin\HashStore;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\ReplicaRequests;
use Drupal\content_relay_origin\SyncScope;
use Drupal\content_relay_origin\TranslationBlocks;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read endpoints for replicas: the schema, single items and item hashes.
 *
 * A custom endpoint rather than JSON:API: it answers in exactly the snapshot
 * form events use, with hashes, so a pulled item is applied like any event;
 * it is authorized per replica by its contract rather than by a Drupal user
 * and role; and its answers are signed, and sealed when the contract is
 * encrypted. It only reads. Both endpoints authenticate the replica's JWT.
 *
 * - GET /content-relay/v1/schema: the synced fields of every bundle the
 *   replica may subscribe to, and this site's languages.
 * - GET /content-relay/v1/items/{entity_type}/{uuid}: the item as an event
 *   built under the replica's contract, when the replica may have it.
 * - GET /content-relay/v1/hashes?entity_type=&bundle=&after=&limit=: the
 *   UUID, latest revision, changed time and hashes of every item of a bundle
 *   the replica receives, a page at a time in UUID order. Reconciliation
 *   compares these with what the replica has, without loading entities.
 * - GET /content-relay/v1/stream?sequences=: what the events with those
 *   sequence numbers were about, and what each item is now, for a replica
 *   filling a gap in the stream.
 */
final class ReadController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ReplicaRequests $requests,
    private readonly ContractManager $contracts,
    private readonly SyncScope $scope,
    private readonly SchemaDescriber $describer,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventBuilder $eventBuilder,
    private readonly NotSyncableFlags $flags,
    private readonly UuidInterface $uuid,
    private readonly HashStore $hashStore,
    private readonly Outbox $outbox,
    private readonly LanguageManagerInterface $languageManager,
    private readonly TranslationBlocks $translationBlocks,
  ) {}

  /**
   * Most items listed in one page of hashes.
   */
  public const MAX_HASHES = 500;

  /**
   * Most sequence numbers asked about at once.
   */
  public const MAX_SEQUENCES = 200;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.replica_requests'),
      $container->get('content_relay_origin.contract_manager'),
      $container->get('content_relay_origin.sync_scope'),
      $container->get('content_relay.schema_describer'),
      $container->get('entity.repository'),
      $container->get('entity_type.manager'),
      $container->get('content_relay_origin.event_builder'),
      $container->get('content_relay_origin.not_syncable'),
      $container->get('uuid'),
      $container->get('content_relay_origin.hash_store'),
      $container->get('content_relay_origin.outbox'),
      $container->get('language_manager'),
      $container->get('content_relay_origin.translation_blocks'),
    );
  }

  /**
   * The schema of the bundles a replica may subscribe to.
   */
  public function schema(Request $request): JsonResponse {
    $replica_id = $this->requests->authenticate($request);
    if ($replica_id instanceof JsonResponse) {
      return $replica_id;
    }
    $published = $this->scope->getPublishedBundles($replica_id);
    $latest = $this->contracts->getLatest($replica_id);
    $allowed = $latest?->terms['origin_terms']['allowed_bundles'] ?? NULL;
    $bundles = [];
    foreach ($published as $entity_type_id => $list) {
      $bundles[$entity_type_id] = $allowed === NULL ? $list : array_values(array_intersect($list, $allowed[$entity_type_id] ?? []));
    }
    // The languages this replica could receive: not those never sent to it.
    $excluded = $latest?->excludedLanguages() ?? [];
    $languages = [];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      if (!in_array($langcode, $excluded, TRUE)) {
        $languages[$langcode] = $language->getName();
      }
    }
    return $this->requests->answer($replica_id, 'schema', [
      'bundles' => $this->describer->describeMany(array_filter($bundles)),
      'languages' => $languages,
    ]);
  }

  /**
   * One item, as an event under the replica's contract.
   */
  public function item(string $entity_type, string $uuid, Request $request): JsonResponse {
    $replica_id = $this->requests->authenticate($request);
    if ($replica_id instanceof JsonResponse) {
      return $replica_id;
    }
    if ($this->requests->remaining($replica_id) < 1) {
      return $this->requests->overBudget($replica_id);
    }
    $this->requests->consume($replica_id, 1);
    $contract = $this->contracts->getActive($replica_id);
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityTypeManager->hasDefinition($entity_type) ? $this->entityRepository->loadEntityByUuid($entity_type, $uuid) : NULL;
    // The same answer whether it does not exist or may not be had.
    if ($contract === NULL || !$entity instanceof ContentEntityInterface || !$this->scope->canSend($entity)
      || !$contract->allowsBundle($entity_type, $entity->bundle()) || $this->flags->isBlocked($entity_type, $uuid, $replica_id)
      || $this->eventBuilder->hasNoLanguagesFor($entity, $contract)) {
      return ReplicaRequests::refuse(404, 'not_found', 'No such item for this replica.');
    }
    // Not part of the stream: a pulled item has no place in the sequence.
    $event = $this->eventBuilder->build([
      'event_id' => $this->uuid->generate(),
      'sequence' => 1,
      'operation' => 'update',
      'entity_type' => $entity_type,
      'bundle' => $entity->bundle(),
      'entity_uuid' => $uuid,
      'replica_id' => $replica_id,
      'source_revision' => NULL,
      'process' => NULL,
      'reason' => NULL,
    ], $contract, NULL);
    if ($event === NULL) {
      return ReplicaRequests::refuse(404, 'not_found', 'No such item for this replica.');
    }
    return $this->requests->answer($replica_id, 'item', ['event' => $event->toArray()]);
  }

  /**
   * The latest hashes of a page of items of one bundle.
   */
  public function hashes(Request $request): JsonResponse {
    $replica_id = $this->requests->authenticate($request);
    if ($replica_id instanceof JsonResponse) {
      return $replica_id;
    }
    $entity_type_id = (string) $request->query->get('entity_type', '');
    $bundle = (string) $request->query->get('bundle', '');
    $after = (string) $request->query->get('after', '');
    $limit = max(1, min(self::MAX_HASHES, (int) $request->query->get('limit', 200), $this->requests->remaining($replica_id)));
    if ($this->requests->remaining($replica_id) < 1) {
      return $this->requests->overBudget($replica_id);
    }
    $contract = $this->contracts->getActive($replica_id);
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($contract === NULL || !$definition instanceof ContentEntityTypeInterface || !$contract->allowsBundle($entity_type_id, $bundle) || !$this->scope->isBundleSynced($entity_type_id, $bundle)) {
      return ReplicaRequests::refuse(404, 'not_found', 'This replica does not receive that bundle.');
    }

    // UUIDs and changed times straight from the tables, without loading the
    // entities; the hashes were stored when each was saved.
    $uuid_key = (string) $definition->getKey('uuid');
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getAggregateQuery()
      ->accessCheck(FALSE)
      ->groupBy($uuid_key)
      ->sort($uuid_key)
      ->range(0, $limit);
    if ($definition->hasKey('bundle')) {
      $query->condition((string) $definition->getKey('bundle'), $bundle);
    }
    if ($after !== '') {
      $query->condition($uuid_key, $after, '>');
    }
    $changed = $definition->entityClassImplements(EntityChangedInterface::class);
    if ($changed) {
      $query->aggregate('changed', 'MAX');
    }
    /** @var array<int, array<string, string|int|null>> $rows */
    $rows = $query->execute();
    $uuids = array_map(fn (array $row) => (string) $row[$uuid_key], $rows);
    $this->requests->consume($replica_id, count($uuids));
    $hashes = $this->hashStore->latestMany($entity_type_id, $uuids);
    $languages = $contract->languagesFor($entity_type_id, $bundle);
    $excluded = $contract->excludedLanguages();
    $blocked = $this->flags->blockedAmong($entity_type_id, $uuids, $replica_id);
    $withheld = $this->translationBlocks->withheldAmong($entity_type_id, $uuids, $replica_id);

    $items = [];
    foreach ($rows as $row) {
      $uuid = (string) $row[$uuid_key];
      if (!isset($hashes[$uuid])) {
        // Saved before this origin kept hashes: computed now, once.
        /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
        $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }
        $hashes[$uuid] = $this->hashStore->record($entity);
      }
      $items[] = [
        'uuid' => $uuid,
        'revision' => $hashes[$uuid]['revision'],
        'changed' => $changed && $row['changed_max'] !== NULL ? (int) $row['changed_max'] : NULL,
        'hash' => $hashes[$uuid]['hashes']->toArray(),
        'not_syncable' => isset($blocked[$uuid]),
        'withheld' => $withheld[$uuid] ?? [],
        // None of its translations is in a language the replica gets.
        'no_languages' => array_filter(array_keys($hashes[$uuid]['hashes']->translations), fn ($langcode) => GenericContentEntityNormalizer::wanted((string) $langcode, $languages, $excluded)) === [],
      ];
    }
    return $this->requests->answer($replica_id, 'hashes', [
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'contract_version' => $contract->version(),
      'items' => $items,
      'next' => count($rows) < $limit || $uuids === [] ? NULL : end($uuids),
    ]);
  }

  /**
   * What some events in the replica's stream were about, and the items now.
   *
   * Events are built when they are sent, from the item as it is then, so a
   * missed event cannot be sent again as it was. Instead the replica learns
   * which item it concerned and where that item stands now: exists (pull
   * it), gone (delete it), not_syncable (kept from this replica) or
   * not_in_contract.
   */
  public function stream(Request $request): JsonResponse {
    $replica_id = $this->requests->authenticate($request);
    if ($replica_id instanceof JsonResponse) {
      return $replica_id;
    }
    $remaining = $this->requests->remaining($replica_id);
    if ($remaining < 1) {
      return $this->requests->overBudget($replica_id);
    }
    // Over budget, the first ones are answered; the replica asks again later.
    $sequences = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query->get('sequences', ''))), fn (int $sequence) => $sequence > 0))), 0, min(self::MAX_SEQUENCES, $remaining));
    $this->requests->consume($replica_id, count($sequences));
    $contract = $this->contracts->getActive($replica_id);
    $events = [];
    foreach ($this->outbox->bySequence($replica_id, $sequences) as $sequence => $row) {
      $events[] = [
        'sequence' => $sequence,
        'operation' => $row['operation'],
        'entity_type' => $row['entity_type'],
        'bundle' => $row['bundle'],
        'uuid' => $row['entity_uuid'],
        'current' => $this->currentState($row['entity_type'], $row['entity_uuid'], $replica_id, $contract),
      ];
    }
    return $this->requests->answer($replica_id, 'stream', [
      'contract_version' => $contract?->version(),
      // The sequences looked up; any others were left for later.
      'answered' => $sequences,
      'events' => $events,
    ]);
  }

  /**
   * Where an item stands for a replica now.
   */
  private function currentState(string $entity_type_id, string $uuid, string $replica_id, ?SubscriptionContract $contract): string {
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityTypeManager->hasDefinition($entity_type_id) ? $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid) : NULL;
    if (!$entity instanceof ContentEntityInterface) {
      return 'gone';
    }
    if ($contract === NULL || !$this->scope->canSend($entity) || !$contract->allowsBundle($entity_type_id, $entity->bundle()) || $this->eventBuilder->hasNoLanguagesFor($entity, $contract)) {
      return 'not_in_contract';
    }
    return $this->flags->isBlocked($entity_type_id, $uuid, $replica_id) ? 'not_syncable' : 'exists';
  }

}
