<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Schema;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Schema\SchemaDescriber;
use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\ContractStore;
use Drupal\content_relay_replica\Mapping\MappingRepository;
use Drupal\content_relay_replica\OriginClient;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Notices when the origin's schema changes for bundles this site receives.
 *
 * Fetches the origin's schema, compares each subscribed bundle with what it
 * was last time, and when it changed checks it again: as it is, against this
 * site's bundle; mapped, for origin fields the mapping neither maps nor
 * ignores. Problems are kept in state, logged, and shown on the status report
 * until they are fixed. Items are flagged as they arrive with unknown fields.
 */
class SchemaWatcher {

  public const STATE = 'content_relay_replica.schema';

  public function __construct(
    protected OriginClient $originClient,
    protected ContractStore $contracts,
    protected MappingRepository $mappings,
    protected CompatibilityChecker $checker,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected ActivePairing $active,
  ) {}

  /**
   * Checks every subscribed bundle against the origin's schema now.
   *
   * @return array<string, array{fingerprint: string, problems: string[], warnings: string[], mode: string, changed: bool}>
   *   By "entity_type.bundle".
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the schema cannot be had.
   */
  public function check(): array {
    $answer = $this->originClient->schemaAndLanguages();
    $schema = $answer['bundles'];
    $previous = $this->state->get($this->active->stateKey(self::STATE), []);
    $results = [];
    $contract = $this->contracts->getCurrent();
    foreach ($contract?->effectiveBundles() ?? [] as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        $key = $entity_type_id . '.' . $bundle;
        $origin = $schema[$entity_type_id][$bundle] ?? NULL;
        if ($origin === NULL) {
          $results[$key] = [
            'fingerprint' => '',
            'problems' => ['The origin no longer describes this bundle.'],
            'warnings' => [],
            'mode' => 'unknown',
            'changed' => TRUE,
          ];
          continue;
        }
        $mapping = $this->mappings->forSource($entity_type_id, $bundle);
        if ($mapping === NULL) {
          $languages = $contract->languagesFor($entity_type_id, $bundle) ?? array_map('strval', array_keys($answer['languages']));
          $check = $this->checker->check($entity_type_id, $bundle, $origin, array_values(array_diff($languages, $contract->excludedLanguages())));
        }
        else {
          $unmapped = $this->checker->unmappedFields($origin, $mapping);
          $check = [
            'problems' => $unmapped === [] ? [] : [sprintf('Origin fields neither mapped nor ignored by mapping %s: %s.', $mapping->id(), implode(', ', $unmapped))],
            'warnings' => [],
          ];
        }
        $fingerprint = SchemaDescriber::fingerprint($origin);
        $changed = isset($previous[$key]['fingerprint']) && $previous[$key]['fingerprint'] !== $fingerprint;
        $results[$key] = $check + [
          'fingerprint' => $fingerprint,
          'mode' => $mapping === NULL ? 'as_is' : 'mapped',
          'changed' => $changed,
        ];
        if ($changed || ($check['problems'] !== [] && ($previous[$key]['problems'] ?? []) !== $check['problems'])) {
          $this->logger->warning('The origin schema for @bundle @how: @problems', [
            '@bundle' => $key,
            '@how' => $changed ? 'changed' : 'does not fit',
            '@problems' => $check['problems'] === [] ? 'no problems for this site' : implode(' ', $check['problems']),
          ]);
        }
      }
    }
    $this->state->set($this->active->stateKey(self::STATE), $results + [
      '_checked' => $this->time->getCurrentTime(),
      '_languages' => $answer['languages'],
    ]);
    return $results;
  }

  /**
   * Checks if a day has passed since the last check. For cron.
   */
  public function checkIfDue(int $interval = 86400): void {
    $last = (int) ($this->state->get($this->active->stateKey(self::STATE), [])['_checked'] ?? 0);
    if ($this->time->getCurrentTime() - $last < $interval) {
      return;
    }
    try {
      $this->check();
    }
    catch (SubscriptionException $e) {
      $this->logger->warning('The origin schema could not be checked: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * The origin's languages at the last check, names by langcode.
   *
   * @return array<string, string>
   *   Languages; empty before the first check.
   */
  public function originLanguages(): array {
    return $this->state->get($this->active->stateKey(self::STATE), [])['_languages'] ?? [];
  }

  /**
   * Bundles with problems from the last check.
   *
   * @return array<string, string[]>
   *   Problems by "entity_type.bundle".
   */
  public function problems(): array {
    $problems = [];
    foreach ($this->state->get($this->active->stateKey(self::STATE), []) as $key => $result) {
      if (!str_starts_with((string) $key, '_') && is_array($result) && !empty($result['problems'])) {
        $problems[$key] = $result['problems'];
      }
    }
    return $problems;
  }

}
