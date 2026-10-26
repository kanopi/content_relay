<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Contract\SubscriptionContract;

/**
 * Computes the effective terms of a contract.
 *
 * The effective subscription is what the replica asked for, limited by what
 * the origin allows. Everything asked for and not granted is listed with the
 * reason, so the replica's administrators can see why.
 */
class ContractNegotiator {

  public function __construct(
    protected SyncScope $scope,
  ) {}

  /**
   * Computes effective terms and refusals.
   *
   * @return array{effective: array, not_granted: array}
   *   The effective section and the list of refusals.
   */
  public function negotiate(array $origin_terms, array $replica_terms, bool $encryption_possible = FALSE, ?string $replica_id = NULL): array {
    $origin_terms += SubscriptionContract::defaultOriginTerms();
    $replica_terms += SubscriptionContract::defaultReplicaTerms();
    $published = $this->scope->getPublishedBundles($replica_id);
    $allowed = $origin_terms['allowed_bundles'] ?? $published;
    $not_granted = [];

    $bundles = [];
    foreach ($replica_terms['bundles'] as $entity_type_id => $list) {
      foreach ((array) $list as $bundle) {
        $key = $entity_type_id . '.' . $bundle;
        if (!in_array($bundle, $published[$entity_type_id] ?? [], TRUE)) {
          $not_granted[] = ['term' => 'bundle', 'value' => $key, 'reason' => 'The origin does not publish it.'];
        }
        elseif (!in_array($bundle, $allowed[$entity_type_id] ?? [], TRUE)) {
          $not_granted[] = ['term' => 'bundle', 'value' => $key, 'reason' => 'Not allowed for this replica.'];
        }
        else {
          $bundles[$entity_type_id][] = $bundle;
        }
      }
    }
    foreach ($bundles as &$list) {
      sort($list);
    }
    unset($list);
    ksort($bundles);

    $languages = $replica_terms['languages'] + [
      'mode' => SubscriptionContract::LANGUAGES_ALL,
      'selected' => [],
      'per_bundle' => [],
    ];
    $max = $origin_terms['max_languages'];
    if ($max !== NULL && $languages['mode'] === SubscriptionContract::LANGUAGES_SELECTED && count($languages['selected']) > $max) {
      foreach (array_slice($languages['selected'], $max) as $langcode) {
        $not_granted[] = [
          'term' => 'language',
          'value' => $langcode,
          'reason' => sprintf('At most %d languages are allowed.', $max),
        ];
      }
      $languages['selected'] = array_slice($languages['selected'], 0, $max);
    }

    // Encrypted when the replica asks for it or either side requires it,
    // and both sides have an encryption key to make it possible.
    $encryption = 'none';
    if ($replica_terms['encryption'] !== 'none' || $replica_terms['require_encryption'] || $origin_terms['require_encryption']) {
      if ($encryption_possible) {
        $encryption = 'sealed';
      }
      else {
        $not_granted[] = ['term' => 'encryption', 'value' => 'sealed', 'reason' => 'Both sites need an encryption key.'];
      }
    }

    $limits = array_filter([
      $origin_terms['max_events_per_minute'],
      $replica_terms['delivery']['max_events_per_minute'] ?? NULL,
    ], fn ($v) => $v !== NULL);

    return [
      'effective' => [
        'bundles' => $bundles,
        'languages' => $languages,
        'processing' => $replica_terms['processing'],
        'max_events_per_minute' => $limits === [] ? NULL : (int) min($limits),
        'windows' => array_values($replica_terms['delivery']['windows'] ?? []),
        'paused' => (bool) ($replica_terms['delivery']['paused'] ?? FALSE),
        'batch_size' => (int) ($replica_terms['delivery']['batch_size'] ?? 50),
        'encryption' => $encryption,
      ],
      'not_granted' => $not_granted,
    ];
  }

}
