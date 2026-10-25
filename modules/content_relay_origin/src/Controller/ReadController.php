<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Schema\SchemaDescriber;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\EventBuilder;
use Drupal\content_relay_origin\NotSyncableFlags;
use Drupal\content_relay_origin\ReplicaRequests;
use Drupal\content_relay_origin\SyncScope;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Read endpoints for replicas: the schema and single items.
 *
 * A custom endpoint rather than JSON:API: it answers in exactly the snapshot
 * form events use, with hashes, so a pulled item is applied like any event;
 * it is authorized per replica by its contract rather than by a Drupal user
 * and role; and its answers are signed, and sealed when the contract is
 * encrypted. It only reads. Both endpoints authenticate the replica's JWT.
 *
 * - GET /content-relay/v1/schema: the synced fields of every bundle the
 *   replica may subscribe to.
 * - GET /content-relay/v1/items/{entity_type}/{uuid}: the item as an event
 *   built under the replica's contract, when the replica may have it.
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
  ) {}

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
    $published = $this->scope->getPublishedBundles();
    $allowed = $this->contracts->getLatest($replica_id)?->terms['origin_terms']['allowed_bundles'] ?? NULL;
    $bundles = [];
    foreach ($published as $entity_type_id => $list) {
      $bundles[$entity_type_id] = $allowed === NULL ? $list : array_values(array_intersect($list, $allowed[$entity_type_id] ?? []));
    }
    return $this->requests->answer($replica_id, 'schema', ['bundles' => $this->describer->describeMany(array_filter($bundles))]);
  }

  /**
   * One item, as an event under the replica's contract.
   */
  public function item(string $entity_type, string $uuid, Request $request): JsonResponse {
    $replica_id = $this->requests->authenticate($request);
    if ($replica_id instanceof JsonResponse) {
      return $replica_id;
    }
    $contract = $this->contracts->getActive($replica_id);
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityTypeManager->hasDefinition($entity_type) ? $this->entityRepository->loadEntityByUuid($entity_type, $uuid) : NULL;
    // The same answer whether it does not exist or may not be had.
    if ($contract === NULL || !$entity instanceof ContentEntityInterface || !$this->scope->canSend($entity)
      || !$contract->allowsBundle($entity_type, $entity->bundle()) || $this->flags->isBlocked($entity_type, $uuid, $replica_id)) {
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

}
