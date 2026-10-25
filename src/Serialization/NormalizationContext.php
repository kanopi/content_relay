<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

/**
 * Options for, and findings from, normalizing one entity.
 *
 * One context is passed down through embedded entities, so the references and
 * problems of a whole paragraph tree are collected in one place.
 */
final class NormalizationContext {

  /**
   * Entities the snapshot references but does not embed, keyed "type:uuid".
   *
   * These are the dependencies a sender queues before the parent, so the
   * receiving site can resolve the references when the parent arrives.
   *
   * @var array<string, array{entity_type: string, uuid: string}>
   */
  public array $references = [];

  /**
   * References to entities that no longer exist, for reporting.
   *
   * @var list<array{field: string, entity_type: string, id: int|string}>
   */
  public array $unresolved = [];

  /**
   * Maps a URL in text that is not one of this site's file URLs to a file.
   *
   * Given the URL, returns the file's UUID and image style, or NULL. A
   * replica that leaves links pointing at its origin sets this, so the
   * origin's URLs normalize to the same tokens as on the origin.
   *
   * @var (\Closure(string): (array{uuid: string, style: string|null}|null))|null
   */
  public ?\Closure $fileUrlResolver = NULL;

  /**
   * Entities currently being normalized, to stop embedding cycles.
   *
   * @var array<string, true>
   */
  public array $stack = [];

  /**
   * Constructs a NormalizationContext.
   *
   * @param string[]|null $langcodes
   *   Only include these translations, for a receiver that filters languages.
   *   NULL for all. Language-neutral translations are always included, and
   *   the default translation is always included because a snapshot cannot
   *   exist without it; whether to send an entity whose only wanted language
   *   is not its default is the caller's decision.
   */
  public function __construct(
    public readonly ?array $langcodes = NULL,
  ) {}

  /**
   * Records a reference to an entity that is not embedded.
   */
  public function addReference(string $entity_type_id, string $uuid): void {
    $this->references[$entity_type_id . ':' . $uuid] = ['entity_type' => $entity_type_id, 'uuid' => $uuid];
  }

}
