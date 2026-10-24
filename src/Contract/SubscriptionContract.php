<?php

declare(strict_types=1);

namespace Drupal\content_relay\Contract;

/**
 * One version of the subscription contract between an origin and a replica.
 *
 * Both sites store every version. The contract says what is sent (bundles,
 * languages), how (processing mode, encryption, rate and delivery windows) and
 * from when (effective_from_sequence). Every event names the version it was
 * built under, and the replica applies it under that version's rules.
 *
 * The terms are three sections plus the result:
 *
 * - origin_terms: what the origin offers this replica.
 * - replica_terms: what the replica asks for.
 * - joint: what both must agree on (schema and hash versions, keys,
 *   encryption).
 * - effective: what is actually in force, computed by the origin as the
 *   intersection of the first two, and not_granted: what was asked for and
 *   refused, with a reason.
 *
 * The contract is a plain array so it is signed, sent and stored exactly as
 * it is; this class only reads it.
 */
final class SubscriptionContract {

  public const LANGUAGES_ALL = 'all';
  public const LANGUAGES_SELECTED = 'selected';

  /**
   * Constructs a SubscriptionContract.
   *
   * @throws \InvalidArgumentException
   *   When the terms are malformed.
   */
  public function __construct(
    public readonly array $terms,
  ) {
    if (!is_int($terms['version'] ?? NULL) || $terms['version'] < 1) {
      throw new \InvalidArgumentException('A contract needs a version of 1 or more.');
    }
    foreach (['origin', 'replica'] as $key) {
      if (!is_string($terms[$key] ?? NULL) || $terms[$key] === '') {
        throw new \InvalidArgumentException(sprintf('A contract needs a "%s" site ID.', $key));
      }
    }
    foreach (['origin_terms', 'replica_terms', 'joint', 'effective'] as $key) {
      if (!is_array($terms[$key] ?? NULL)) {
        throw new \InvalidArgumentException(sprintf('A contract needs a "%s" section.', $key));
      }
    }
    if (!is_array($terms['effective']['bundles'] ?? NULL)) {
      throw new \InvalidArgumentException('A contract needs effective bundles.');
    }
  }

  /**
   * Default replica terms: nothing subscribed, all languages, immediate.
   */
  public static function defaultReplicaTerms(): array {
    return [
      'bundles' => [],
      'languages' => ['mode' => self::LANGUAGES_ALL, 'selected' => [], 'per_bundle' => []],
      'ignored_languages' => [],
      'processing' => ['default' => 'immediate', 'per_bundle' => []],
      'webhook_url' => '',
      'delivery' => ['batch_size' => 50, 'max_events_per_minute' => NULL, 'windows' => [], 'paused' => FALSE],
      'encryption' => 'none',
      'require_encryption' => FALSE,
    ];
  }

  /**
   * Default origin terms: every published bundle, no limits.
   */
  public static function defaultOriginTerms(): array {
    return [
      // NULL: every bundle the origin publishes, now and later.
      'allowed_bundles' => NULL,
      'max_languages' => NULL,
      'file_transports' => ['http'],
      'max_events_per_minute' => NULL,
      'max_payload_bytes' => 16 * 1024 * 1024,
      'require_encryption' => FALSE,
    ];
  }

  /**
   * Reads a contract from its array form.
   */
  public static function fromArray(array $terms): self {
    return new self($terms);
  }

  /**
   * The array form, as signed and stored.
   */
  public function toArray(): array {
    return $this->terms;
  }

  /**
   * The version.
   */
  public function version(): int {
    return $this->terms['version'];
  }

  /**
   * The origin's site ID.
   */
  public function originId(): string {
    return $this->terms['origin'];
  }

  /**
   * The replica's site ID.
   */
  public function replicaId(): string {
    return $this->terms['replica'];
  }

  /**
   * The first event sequence this version applies to.
   */
  public function effectiveFromSequence(): int {
    return (int) ($this->terms['effective_from_sequence'] ?? 1);
  }

  /**
   * Bundles in force, by entity type.
   *
   * @return array<string, string[]>
   *   Bundles.
   */
  public function effectiveBundles(): array {
    return $this->terms['effective']['bundles'];
  }

  /**
   * Whether a bundle is in force.
   */
  public function allowsBundle(string $entity_type_id, string $bundle): bool {
    return in_array($bundle, $this->effectiveBundles()[$entity_type_id] ?? [], TRUE);
  }

  /**
   * The languages sent for a bundle.
   *
   * @return string[]|null
   *   Origin langcodes, or NULL for every language.
   */
  public function languagesFor(string $entity_type_id, string $bundle): ?array {
    $languages = $this->terms['effective']['languages'] ?? ['mode' => self::LANGUAGES_ALL];
    $languages = $languages['per_bundle'][$entity_type_id . '.' . $bundle] ?? $languages;
    return ($languages['mode'] ?? self::LANGUAGES_ALL) === self::LANGUAGES_SELECTED ? array_values($languages['selected'] ?? []) : NULL;
  }

  /**
   * The processing mode the replica asked for, for a bundle.
   */
  public function processingFor(string $entity_type_id, string $bundle): string {
    $processing = $this->terms['effective']['processing'] ?? [];
    return $processing['per_bundle'][$entity_type_id . '.' . $bundle] ?? $processing['default'] ?? 'immediate';
  }

  /**
   * Whether the replica has paused delivery.
   */
  public function isPaused(): bool {
    return (bool) ($this->terms['effective']['paused'] ?? FALSE);
  }

  /**
   * Most events per minute, or NULL for no limit.
   */
  public function maxEventsPerMinute(): ?int {
    $limit = $this->terms['effective']['max_events_per_minute'] ?? NULL;
    return $limit === NULL ? NULL : (int) $limit;
  }

  /**
   * Delivery windows, as [start, end] "HH:MM" pairs in UTC. Empty for always.
   *
   * @return array<array{start: string, end: string}>
   *   Windows.
   */
  public function deliveryWindows(): array {
    return $this->terms['effective']['windows'] ?? [];
  }

  /**
   * Whether a moment falls inside a delivery window.
   */
  public function isWithinWindow(int $timestamp): bool {
    $windows = $this->deliveryWindows();
    if ($windows === []) {
      return TRUE;
    }
    $minute = (int) gmdate('G', $timestamp) * 60 + (int) gmdate('i', $timestamp);
    foreach ($windows as $window) {
      [$start, $end] = [self::minutes($window['start']), self::minutes($window['end'])];
      // A window that ends before it starts runs past midnight.
      if ($start <= $end ? ($minute >= $start && $minute < $end) : ($minute >= $start || $minute < $end)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The encryption mode in force.
   */
  public function encryption(): string {
    return (string) ($this->terms['effective']['encryption'] ?? 'none');
  }

  /**
   * What was asked for and not granted.
   *
   * @return array<array{term: string, value: string, reason: string}>
   *   Refusals.
   */
  public function notGranted(): array {
    return $this->terms['not_granted'] ?? [];
  }

  /**
   * Differences from an earlier version, as dotted paths.
   *
   * Bookkeeping that changes every version (version, issued time, effective
   * sequence) is left out, so the list is what someone actually changed.
   *
   * @return array<array{path: string, old: mixed, new: mixed}>
   *   Changes, sorted by path.
   */
  public function diff(?SubscriptionContract $previous): array {
    $ignore = ['version', 'issued', 'effective_from_sequence', 'issued_by'];
    $old = $previous ? self::flatten(array_diff_key($previous->terms, array_flip($ignore))) : [];
    $new = self::flatten(array_diff_key($this->terms, array_flip($ignore)));
    $changes = [];
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $path) {
      if (($old[$path] ?? NULL) !== ($new[$path] ?? NULL)) {
        $changes[] = ['path' => (string) $path, 'old' => $old[$path] ?? NULL, 'new' => $new[$path] ?? NULL];
      }
    }
    usort($changes, fn ($a, $b) => strcmp($a['path'], $b['path']));
    return $changes;
  }

  /**
   * Flattens nested maps to dotted paths. Lists stay whole, sorted.
   */
  private static function flatten(array $values, string $prefix = ''): array {
    $flat = [];
    foreach ($values as $key => $value) {
      $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
      if (is_array($value) && $value !== [] && !array_is_list($value)) {
        $flat += self::flatten($value, $path);
      }
      else {
        if (is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value) {
          sort($value);
        }
        $flat[$path] = $value;
      }
    }
    return $flat;
  }

  /**
   * Minutes after midnight for "HH:MM".
   */
  private static function minutes(string $time): int {
    [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);
    return $hours * 60 + $minutes;
  }

}
