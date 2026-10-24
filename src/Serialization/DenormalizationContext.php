<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

/**
 * Options for, and findings from, turning a snapshot back into an entity.
 */
final class DenormalizationContext {

  /**
   * Referenced entities that do not exist here, keyed "type:uuid".
   *
   * Their items were left out. A caller applying an event treats a non-empty
   * list as "waiting for a dependency" rather than saving a partial entity.
   *
   * @var array<string, array{entity_type: string, uuid: string, field: string}>
   */
  public array $missingReferences = [];

  /**
   * Snapshot fields the local entity does not have, keyed by field name.
   *
   * Their values were ignored. This is how schema drift shows up.
   *
   * @var array<string, true>
   */
  public array $unknownFields = [];

  /**
   * Snapshot translations that could not be applied, keyed by langcode.
   *
   * @var array<string, string>
   */
  public array $skippedTranslations = [];

  /**
   * Notes about decisions taken while applying, for the sync log.
   *
   * @var string[]
   */
  public array $warnings = [];

  /**
   * Constructs a DenormalizationContext.
   *
   * @param string[] $preserveFields
   *   Fields never written: the receiver's local-only fields.
   * @param string[]|null $managedLangcodes
   *   Translations this sync owns. A managed translation that is missing from
   *   the snapshot is removed from the entity; any other translation is left
   *   alone, because it is local or belongs to a language this receiver does
   *   not get. NULL means every translation is managed.
   */
  public function __construct(
    public readonly array $preserveFields = [],
    public readonly ?array $managedLangcodes = NULL,
  ) {}

  /**
   * Records a reference whose target does not exist here.
   */
  public function addMissingReference(string $entity_type_id, string $uuid, string $field): void {
    $this->missingReferences[$entity_type_id . ':' . $uuid] = [
      'entity_type' => $entity_type_id,
      'uuid' => $uuid,
      'field' => $field,
    ];
  }

}
