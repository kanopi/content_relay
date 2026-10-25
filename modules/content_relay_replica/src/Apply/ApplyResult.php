<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Apply;

/**
 * The outcome of applying an event, and the answer sent back for it.
 *
 * The body never contains stack traces, file paths or exception messages from
 * deep in the stack: only a result, a code and a short summary written for
 * the origin's administrators. The full details stay on the replica.
 */
final class ApplyResult {

  public const APPLIED = 'applied';
  public const UNCHANGED = 'unchanged';
  public const DELETED = 'deleted';
  public const ACCEPTED = 'accepted';
  public const STALE = 'stale';
  // Edited on the replica; the origin's version was not applied.
  public const CONFLICT = 'conflict';
  // The item is detached on this replica; the event was not applied.
  public const SKIPPED_DETACHED = 'skipped_detached';
  // A detach or attach event was recorded.
  public const DETACHED = 'detached';
  public const ATTACHED = 'attached';
  // The event names a contract version this replica does not accept.
  public const CONTRACT_MISMATCH = 'contract_mismatch';
  public const APPLY_FAILED = 'apply_failed';
  public const REJECTED = 'rejected';
  public const BUSY = 'busy';

  /**
   * Constructs an ApplyResult.
   *
   * @param int $httpStatus
   *   The HTTP status to answer with.
   * @param string $result
   *   One of the class constants.
   * @param string|null $code
   *   A more specific machine-readable code.
   * @param string|null $message
   *   A short summary safe to send to the origin.
   * @param array $details
   *   Extra safe details, such as field violations.
   */
  public function __construct(
    public readonly int $httpStatus,
    public readonly string $result,
    public readonly ?string $code = NULL,
    public readonly ?string $message = NULL,
    public readonly array $details = [],
  ) {}

  /**
   * The response body.
   */
  public function toArray(string $event_id): array {
    return array_filter([
      'result' => $this->result,
      'event_id' => $event_id,
      'code' => $this->code,
      'message' => $this->message,
      'details' => $this->details,
    ], fn ($value) => $value !== NULL && $value !== []);
  }

  /**
   * Rebuilds a result from a recorded answer.
   */
  public static function fromRecord(array $record): self {
    $body = $record['result'];
    return new self($record['http_status'], (string) ($body['result'] ?? self::APPLIED), $body['code'] ?? NULL, $body['message'] ?? NULL, $body['details'] ?? []);
  }

}
