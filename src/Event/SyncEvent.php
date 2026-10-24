<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event;

use Drupal\Component\Uuid\Uuid;
use Drupal\content_relay\Exception\InvalidEventException;
use Drupal\content_relay\Hash\RevisionHashes;
use Drupal\content_relay\Serialization\Snapshot;

/**
 * One change to one entity, as sent from the origin to a replica.
 *
 * Events are immutable and built for one replica: the sequence number, the
 * contract version and the translations included all depend on who receives
 * it. An event is the body of a signed message; it does not sign itself.
 *
 * The schema is versioned by the "v" key. A receiver refuses versions it does
 * not know rather than guessing, because a field it ignores might be the one
 * that changes what the event means.
 *
 * Identity is by UUID only. The source revision is the origin's revision ID,
 * or a version counter for entity types without revisions, and is compared
 * only with earlier values from the same origin for the same entity.
 */
final class SyncEvent {

  /**
   * The schema version this class reads and writes.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * Constructs a SyncEvent.
   *
   * @param string $eventId
   *   UUID of this event. Retries and replays of the same change reuse it,
   *   which is how a replica recognizes a duplicate.
   * @param int $sequence
   *   Position in the stream to this replica, starting at 1, without gaps.
   * @param \Drupal\content_relay\Event\Operation $operation
   *   What to do.
   * @param string $entityType
   *   Entity type ID.
   * @param string $bundle
   *   Bundle.
   * @param string $entityUuid
   *   UUID of the entity.
   * @param int $sourceRevision
   *   Origin revision ID, or version counter for non-revisionable types.
   * @param bool $revisionable
   *   Whether $sourceRevision is a revision ID (TRUE) or a counter (FALSE).
   * @param int|null $sourceChanged
   *   Origin changed timestamp, when the entity type has one.
   * @param string $defaultLangcode
   *   Origin default langcode.
   * @param array $langcodes
   *   Translations included, in origin langcodes. Checked to be strings.
   * @param \Drupal\content_relay\Hash\RevisionHashes|null $hashes
   *   Hashes of the snapshot. Required with a snapshot.
   * @param array|null $snapshot
   *   The full entity. Required for create and update, absent otherwise.
   * @param int $contractVersion
   *   Version of the subscription contract the event was built under.
   * @param int $created
   *   When the event was built on the origin, as a Unix timestamp.
   * @param array $dependencies
   *   Entities the snapshot references, queued before this event: each an
   *   array with entity_type and uuid. Checked on construction.
   * @param array $files
   *   File metadata for binaries to fetch.
   * @param \Drupal\content_relay\Event\ProcessingMode|null $process
   *   A per-event override of the replica's processing mode.
   * @param int|null $previousSequence
   *   The sequence of the event sent to this replica before this one, or NULL
   *   for the first. Sequence numbers can be skipped on purpose (an update
   *   superseded by a later one is never sent), so a replica detects a lost
   *   event by comparing this with the last sequence it received, not by
   *   expecting consecutive numbers.
   */
  public function __construct(
    public readonly string $eventId,
    public readonly int $sequence,
    public readonly Operation $operation,
    public readonly string $entityType,
    public readonly string $bundle,
    public readonly string $entityUuid,
    public readonly int $sourceRevision,
    public readonly bool $revisionable,
    public readonly ?int $sourceChanged,
    public readonly string $defaultLangcode,
    public readonly array $langcodes,
    public readonly ?RevisionHashes $hashes,
    public readonly ?array $snapshot,
    public readonly int $contractVersion,
    public readonly int $created,
    public readonly array $dependencies = [],
    public readonly array $files = [],
    public readonly ?ProcessingMode $process = NULL,
    public readonly ?int $previousSequence = NULL,
  ) {
    $this->validate();
  }

  /**
   * Returns the event as the array carried in a message body.
   */
  public function toArray(): array {
    return [
      'v' => self::SCHEMA_VERSION,
      'event_id' => $this->eventId,
      'sequence' => $this->sequence,
      'previous_sequence' => $this->previousSequence,
      'operation' => $this->operation->value,
      'entity_type' => $this->entityType,
      'bundle' => $this->bundle,
      'entity_uuid' => $this->entityUuid,
      'source_revision' => $this->sourceRevision,
      'revisionable' => $this->revisionable,
      'source_changed' => $this->sourceChanged,
      'default_langcode' => $this->defaultLangcode,
      'langcodes' => $this->langcodes,
      'hash' => $this->hashes?->toArray(),
      'contract_version' => $this->contractVersion,
      'created' => $this->created,
      'dependencies' => $this->dependencies,
      'files' => $this->files,
      'process' => $this->process?->value,
      'snapshot' => $this->snapshot,
    ];
  }

  /**
   * Reads an event from a decoded message body.
   *
   * @throws \Drupal\content_relay\Exception\InvalidEventException
   *   When the data does not match the schema.
   */
  public static function fromArray(array $data): self {
    if (($data['v'] ?? NULL) !== self::SCHEMA_VERSION) {
      throw new InvalidEventException(sprintf('Unsupported event schema version %s.', json_encode($data['v'] ?? NULL)));
    }
    $require = static function (string $key, string $type) use ($data): mixed {
      $value = $data[$key] ?? NULL;
      $ok = match ($type) {
        'string' => is_string($value) && $value !== '',
        'int' => is_int($value),
        '?int' => $value === NULL || is_int($value),
        'bool' => is_bool($value),
        'array' => is_array($value),
        '?array' => $value === NULL || is_array($value),
        '?string' => $value === NULL || is_string($value),
        default => throw new \LogicException("Unknown type $type."),
      };
      if (!$ok) {
        throw new InvalidEventException(sprintf('Event field "%s" must be %s.', $key, $type));
      }
      return $value;
    };

    $operation = Operation::tryFrom($require('operation', 'string'))
      ?? throw new InvalidEventException('Unknown event operation.');
    $process = $require('process', '?string');
    $hash = $require('hash', '?array');
    try {
      $hashes = $hash === NULL ? NULL : RevisionHashes::fromArray($hash);
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidEventException($e->getMessage(), 0, $e);
    }

    return new self(
      eventId: $require('event_id', 'string'),
      sequence: $require('sequence', 'int'),
      previousSequence: $require('previous_sequence', '?int'),
      operation: $operation,
      entityType: $require('entity_type', 'string'),
      bundle: $require('bundle', 'string'),
      entityUuid: $require('entity_uuid', 'string'),
      sourceRevision: $require('source_revision', 'int'),
      revisionable: $require('revisionable', 'bool'),
      sourceChanged: $require('source_changed', '?int'),
      defaultLangcode: $require('default_langcode', 'string'),
      langcodes: $require('langcodes', 'array'),
      hashes: $hashes,
      snapshot: $require('snapshot', '?array'),
      contractVersion: $require('contract_version', 'int'),
      created: $require('created', 'int'),
      dependencies: $require('dependencies', 'array'),
      files: $require('files', 'array'),
      process: $process === NULL ? NULL : (ProcessingMode::tryFrom($process) ?? throw new InvalidEventException('Unknown processing mode.')),
    );
  }

  /**
   * Checks the rules that span several fields.
   *
   * @throws \Drupal\content_relay\Exception\InvalidEventException
   */
  private function validate(): void {
    foreach (['eventId' => $this->eventId, 'entityUuid' => $this->entityUuid] as $name => $uuid) {
      if (!Uuid::isValid($uuid)) {
        throw new InvalidEventException(sprintf('Event %s is not a valid UUID.', $name));
      }
    }
    if ($this->sequence < 1 || $this->sourceRevision < 0 || $this->contractVersion < 1) {
      throw new InvalidEventException('Event sequence and contract version start at 1, and revisions cannot be negative.');
    }
    if ($this->previousSequence !== NULL && ($this->previousSequence < 1 || $this->previousSequence >= $this->sequence)) {
      throw new InvalidEventException('The previous sequence must come before this one.');
    }
    if (!array_is_list($this->langcodes) || array_filter($this->langcodes, fn ($l) => !is_string($l) || $l === '')) {
      throw new InvalidEventException('Event langcodes must be a list of strings.');
    }
    foreach ($this->dependencies as $dependency) {
      if (!is_array($dependency) || !is_string($dependency['entity_type'] ?? NULL) || !is_string($dependency['uuid'] ?? NULL)) {
        throw new InvalidEventException('Event dependencies need an entity type and a UUID.');
      }
    }

    if (!$this->operation->carriesSnapshot()) {
      if ($this->snapshot !== NULL || $this->hashes !== NULL) {
        throw new InvalidEventException(sprintf('A %s event carries no snapshot.', $this->operation->value));
      }
      return;
    }

    if ($this->snapshot === NULL || $this->hashes === NULL) {
      throw new InvalidEventException(sprintf('A %s event needs a snapshot and its hashes.', $this->operation->value));
    }
    try {
      Snapshot::assertValid($this->snapshot);
    }
    catch (\InvalidArgumentException $e) {
      throw new InvalidEventException($e->getMessage(), 0, $e);
    }
    // The envelope fields must describe the snapshot they carry, or a reader
    // trusting the summary would act on a different entity than it saves.
    if ($this->snapshot['entity_type'] !== $this->entityType || $this->snapshot['bundle'] !== $this->bundle || $this->snapshot['uuid'] !== $this->entityUuid || $this->snapshot['default_langcode'] !== $this->defaultLangcode) {
      throw new InvalidEventException('Event fields do not match its snapshot.');
    }
    $included = Snapshot::langcodes($this->snapshot);
    $declared = $this->langcodes;
    sort($included);
    sort($declared);
    if ($included !== $declared) {
      throw new InvalidEventException('Event langcodes do not match the translations in its snapshot.');
    }
  }

}
