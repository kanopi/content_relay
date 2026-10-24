<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\content_relay\Event\Hook\ContentRelayEvents;
use Drupal\content_relay\Event\Hook\SerializeEvent;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns entities into snapshots and snapshots into entities.
 *
 * The single entry point for both directions. It picks the normalizer plugin
 * for the entity type, dispatches the serialize events around it, and checks
 * the result is a well-formed snapshot before anyone hashes or sends it.
 */
class EntitySerializer {

  public function __construct(
    protected NormalizerManager $normalizerManager,
    protected EntityRepositoryInterface $entityRepository,
    protected UuidResolver $uuidResolver,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Builds the snapshot of an entity.
   *
   * @throws \InvalidArgumentException
   *   When the entity type cannot be synced.
   */
  public function normalize(ContentEntityInterface $entity, ?NormalizationContext $context = NULL): array {
    $context ??= new NormalizationContext();
    $key = $entity->getEntityTypeId() . ':' . $entity->uuid();
    if (isset($context->stack[$key])) {
      throw new \LogicException(sprintf('Entity %s embeds itself.', $key));
    }
    $context->stack[$key] = TRUE;

    try {
      $this->eventDispatcher->dispatch(new SerializeEvent($entity, $context), ContentRelayEvents::PRE_SERIALIZE);
      $snapshot = $this->normalizerManager->getNormalizer($entity->getEntityTypeId())->normalize($entity->getUntranslated(), $context, $this);
      $event = new SerializeEvent($entity, $context, $snapshot);
      $this->eventDispatcher->dispatch($event, ContentRelayEvents::POST_SERIALIZE);
      Snapshot::assertValid($event->snapshot);
      return $event->snapshot;
    }
    finally {
      unset($context->stack[$key]);
    }
  }

  /**
   * Applies a snapshot to the local entity with its UUID, or a new one.
   *
   * Nothing is saved. Whether the local entity may be overwritten at all is
   * not decided here: the caller checks its sync state first.
   *
   * @param array $snapshot
   *   The snapshot.
   * @param \Drupal\content_relay\Serialization\DenormalizationContext|null $context
   *   Options and collected problems.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The entity to update. When NULL, the entity with the snapshot's UUID is
   *   loaded, or a new one created.
   *
   * @throws \InvalidArgumentException
   *   When the snapshot is malformed or its type cannot be synced.
   */
  public function denormalize(array $snapshot, ?DenormalizationContext $context = NULL, ?ContentEntityInterface $entity = NULL): ContentEntityInterface {
    Snapshot::assertValid($snapshot);
    $context ??= new DenormalizationContext();
    $normalizer = $this->normalizerManager->getNormalizer($snapshot['entity_type']);
    if ($entity === NULL) {
      /** @var \Drupal\Core\Entity\EntityInterface|null $existing */
      $existing = $this->entityRepository->loadEntityByUuid($snapshot['entity_type'], $snapshot['uuid']);
      $entity = $existing instanceof ContentEntityInterface ? $existing : NULL;
    }
    elseif ($entity->getEntityTypeId() !== $snapshot['entity_type'] || $entity->uuid() !== $snapshot['uuid']) {
      throw new \InvalidArgumentException('The entity to update does not match the snapshot.');
    }
    return $normalizer->denormalize($snapshot, $entity?->getUntranslated(), $context, $this);
  }

  /**
   * The resolver used for references, to prime lookups across many entities.
   */
  public function getUuidResolver(): UuidResolver {
    return $this->uuidResolver;
  }

  /**
   * The normalizer plugin for an entity type.
   */
  public function getNormalizer(string $entity_type_id): EntityNormalizerInterface {
    return $this->normalizerManager->getNormalizer($entity_type_id);
  }

}
