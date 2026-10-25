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
   * The local default language, when it was kept over the snapshot's.
   *
   * Set when the copy's default language differs from the sender's, which
   * happens when the receiver does not get the sender's default language.
   */
  public ?string $defaultLanguageKept = NULL;

  /**
   * Notes about decisions taken while applying, for the sync log.
   *
   * @var string[]
   */
  public array $warnings = [];

  /**
   * Where in the snapshot the item being denormalized is.
   *
   * A path of keys from the snapshot root to the field's item list, set by
   * the entity normalizer; with $delta it locates one item. Embedded entities
   * extend it with their parent item's path.
   *
   * @var array<int|string>
   */
  public array $location = [];

  /**
   * The delta of the item being denormalized, in the snapshot.
   */
  public int $delta = 0;

  /**
   * Path prefix for an embedded entity being denormalized.
   *
   * @var array<int|string>
   */
  public array $prefix = [];

  /**
   * Decides the URL a file token in text becomes.
   *
   * Given the file's UUID and image style, returns the URL to write, or NULL
   * when the file is not here yet. Without it, files get this site's own
   * URLs.
   *
   * @var (\Closure(string, string|null): (string|null))|null
   */
  public ?\Closure $fileUrl = NULL;

  /**
   * Items replaced or dropped by local policy rather than copied.
   *
   * Applying them to the received snapshot gives what the saved entity should
   * hash to. Without that, a reference this site deliberately resolved
   * differently would look like a failed copy.
   *
   * @var array<array{location: array<int|string>, delta: int, item: array|null}>
   */
  public array $substitutions = [];

  /**
   * Constructs a DenormalizationContext.
   *
   * @param string[] $preserveFields
   *   Fields of the applied entity never written: the receiver's local-only
   *   fields. Entities embedded in it are not affected.
   * @param string[]|null $managedLangcodes
   *   Translations this sync owns. A managed translation that is missing from
   *   the snapshot is removed from the entity; any other translation is left
   *   alone, because it is local or belongs to a language this receiver does
   *   not get. NULL means every translation is managed.
   * @param array<string, true> $external
   *   References to entities outside the subscription, keyed "type:uuid". The
   *   sender will never send them, so waiting for them is pointless.
   * @param \Closure|null $externalFallback
   *   Decides what an external reference that does not exist here becomes:
   *   given the entity type and UUID, returns the local entity ID to use
   *   instead, or NULL to leave the reference out.
   */
  public function __construct(
    public readonly array $preserveFields = [],
    public readonly ?array $managedLangcodes = NULL,
    public readonly array $external = [],
    public readonly ?\Closure $externalFallback = NULL,
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

  /**
   * Whether a reference points outside the subscription.
   */
  public function isExternal(string $entity_type_id, string $uuid): bool {
    return isset($this->external[$entity_type_id . ':' . $uuid]);
  }

  /**
   * Records that the current item was replaced, or dropped when NULL.
   *
   * @param array|null $item
   *   The replacement, in portable form.
   */
  public function substitute(?array $item): void {
    $this->substitutions[] = ['location' => $this->location, 'delta' => $this->delta, 'item' => $item];
  }

  /**
   * Applies recorded substitutions to a snapshot.
   */
  public function applySubstitutions(array $snapshot): array {
    // Replace first, then drop from the highest delta down, so deltas still
    // point at the right items while the list shrinks.
    $substitutions = $this->substitutions;
    usort($substitutions, fn ($a, $b) => [$a['item'] === NULL, $b['delta']] <=> [$b['item'] === NULL, $a['delta']]);
    foreach ($substitutions as $substitution) {
      $location = $substitution['location'];
      $field = array_pop($location);
      $parent = &$snapshot;
      foreach ($location as $key) {
        if (!isset($parent[$key]) || !is_array($parent[$key])) {
          unset($parent);
          continue 2;
        }
        $parent = &$parent[$key];
      }
      if (!isset($parent[$field][$substitution['delta']])) {
        unset($parent);
        continue;
      }
      if ($substitution['item'] === NULL) {
        unset($parent[$field][$substitution['delta']]);
        $parent[$field] = array_values($parent[$field]);
        // An empty field is an absent one; see Snapshot.
        if ($parent[$field] === []) {
          unset($parent[$field]);
        }
      }
      else {
        $parent[$field][$substitution['delta']] = $substitution['item'];
      }
      unset($parent);
    }
    return $snapshot;
  }

}
