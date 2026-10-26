<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\content_relay\Serialization\LatestRevision;
use Drupal\content_relay_replica\Apply\SyncApplier;
use Drupal\content_relay_replica\Mapping\MappingRepository;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes over content this site already has, for a replica made from a copy.
 *
 * A replica set up from a copy of the origin's database has the origin's
 * items under the same UUIDs, made outside sync. Sync leaves such items
 * alone. Adopting compares each one with the origin's hashes and gives it a
 * sync state: an identical copy is in sync, with no new revision; a copy that
 * differs counts as edited here, so it is a conflict, resolved as any other.
 *
 * Only items the contract subscribes to, as they are (not mapped), are
 * adopted; items already synced, missing here or kept from this site are
 * left as they are. Adopting can be done at any time, also once sync has been
 * running: events refused before an item was adopted are retried after, and
 * items adopted as differing that nobody has resolved are compared again.
 */
class Adopter {

  /**
   * Items per page of the origin's hash listing.
   */
  public const PAGE = 200;

  public function __construct(
    protected OriginClient $originClient,
    protected OriginPeer $origin,
    protected ContractStore $contracts,
    protected SyncStateStore $syncState,
    protected LocalChanges $localChanges,
    protected MappingRepository $mappings,
    protected LanguageMap $languageMap,
    protected EntityRepositoryInterface $entityRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FailedEventStore $failures,
    protected SyncApplier $applier,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The entity types and bundles this site subscribes to.
   *
   * @return list<array{0: string, 1: string}>
   *   Entity type and bundle pairs.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When there is no contract.
   */
  public function bundles(): array {
    $contract = $this->contracts->getCurrent() ?? throw new SubscriptionException('This site has no contract with an origin yet.');
    $bundles = [];
    foreach ($contract->effectiveBundles() as $entity_type_id => $ids) {
      foreach ($ids as $bundle) {
        $bundles[] = [(string) $entity_type_id, (string) $bundle];
      }
    }
    return $bundles;
  }

  /**
   * Adopts every subscribed item.
   *
   * @param callable(string, string, array): void|null $progress
   *   Told the entity type, bundle and counts so far after each page.
   *
   * @return array<string, int>
   *   Counts by outcome; see adoptPage().
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   *   When the origin cannot be asked, or asks this site to wait; adopting
   *   again carries on, as adopted items are skipped.
   */
  public function run(?callable $progress = NULL): array {
    $counts = [];
    foreach ($this->bundles() as [$entity_type_id, $bundle]) {
      $after = '';
      do {
        $page = $this->adoptPage($entity_type_id, $bundle, $after);
        foreach ($page['counts'] as $outcome => $number) {
          $counts[$outcome] = ($counts[$outcome] ?? 0) + $number;
        }
        if ($progress !== NULL) {
          $progress($entity_type_id, $bundle, $counts);
        }
        $after = $page['next'];
      } while ($after !== NULL);
    }
    $this->logger->notice('Adopted existing content: @counts.', ['@counts' => json_encode($counts)]);
    return $counts;
  }

  /**
   * Adopts the items of one page of the origin's hash listing.
   *
   * @return array{counts: array<string, int>, next: string|null}
   *   Counts by outcome: adopted (identical), adopted_edited (differs, now a
   *   conflict), already_synced, missing (not here; reconciliation pulls
   *   it), kept (not syncable or no languages for this site), mapped and
   *   other_bundle (left alone); and retried, events refused before the
   *   item was adopted and applied now. And the cursor for the next page.
   *
   * @throws \Drupal\content_relay_replica\SubscriptionException
   */
  public function adoptPage(string $entity_type_id, string $bundle, string $after = ''): array {
    $page = $this->originClient->hashes($entity_type_id, $bundle, $after, self::PAGE);
    $mapped = $this->mappings->forSource($entity_type_id, $bundle) !== NULL;
    $contract = $this->contracts->getCurrent();
    $languages = $contract?->languagesFor($entity_type_id, $bundle);
    $excluded = $contract?->excludedLanguages() ?? [];
    $counts = [];
    foreach ($page['items'] as $item) {
      $state = $this->syncState->get($entity_type_id, $item['uuid']);
      $outcome = match (TRUE) {
        $state !== NULL && !self::unresolved($state) => 'already_synced',
        $item['not_syncable'] || $item['no_languages'] => 'kept',
        $mapped => 'mapped',
        default => $this->adoptItem($entity_type_id, $bundle, $item, $languages, $excluded),
      };
      $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
      if (in_array($outcome, ['adopted', 'adopted_edited'], TRUE) && ($retried = $this->retryRefused($entity_type_id, $item['uuid'])) > 0) {
        $counts['retried'] = ($counts['retried'] ?? 0) + $retried;
      }
    }
    return ['counts' => $counts, 'next' => $page['next']];
  }

  /**
   * Whether an item was adopted as differing and is still unresolved.
   *
   * Such an item is compared again, as the copy may have been put right since,
   * for example by copying files it uses. Anything else synced is left alone.
   */
  protected static function unresolved(array $state): bool {
    return isset($state['notes']['adopted']) && $state['status'] === SyncStatus::CONFLICT && $state['conflict_revision'] === NULL && $state['last_event_id'] === NULL;
  }

  /**
   * Retries the events refused for an item before it was adopted.
   *
   * @return int
   *   How many were retried.
   */
  protected function retryRefused(string $entity_type_id, string $uuid): int {
    $retried = 0;
    foreach ($this->failures->getOpenForEntity($entity_type_id, $uuid) as $failure) {
      if ($failure['error_code'] === 'uuid_collision') {
        $this->applier->retry((int) $failure['id']);
        $retried++;
      }
    }
    return $retried;
  }

  /**
   * Adopts one item.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param array $item
   *   The item's entry in the origin's hash listing.
   * @param string[]|null $languages
   *   The languages this site receives for the bundle, NULL for all.
   * @param string[] $excluded
   *   Languages the origin does not send.
   *
   * @return string
   *   The outcome.
   */
  protected function adoptItem(string $entity_type_id, string $bundle, array $item, ?array $languages, array $excluded = []): string {
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $item['uuid']);
    if (!$entity instanceof ContentEntityInterface) {
      return 'missing';
    }
    if ($entity->bundle() !== $bundle) {
      return 'other_bundle';
    }
    $entity = LatestRevision::of($entity, $this->entityTypeManager);

    // The translations this site receives: the origin's, less what it
    // withholds, filtered by the contract and languages this site has.
    $origin = array_map('strval', is_array($item['hash']['translations'] ?? NULL) ? $item['hash']['translations'] : []);
    $received = [];
    foreach ($origin as $langcode => $hash) {
      $langcode = (string) $langcode;
      if (!in_array($langcode, $item['withheld'], TRUE) && GenericContentEntityNormalizer::wanted($langcode, $languages, $excluded) && $this->languageMap->exists($this->languageMap->toLocal($langcode))) {
        $received[$langcode] = $hash;
      }
    }
    $algorithm = (int) ($item['hash']['algorithm'] ?? 0);
    $default = $this->languageMap->toOrigin($entity->getUntranslated()->language()->getId());
    $local = $this->localChanges->hash($entity, array_keys($received), $algorithm, $default);
    $differing = [];
    foreach ($received as $langcode => $hash) {
      if (!isset($local->translations[$langcode]) || !hash_equals($hash, $local->translations[$langcode])) {
        $differing[] = $langcode;
      }
    }

    $fields = [
      'bundle' => $bundle,
      'source_site' => (string) $this->origin->getSiteId(),
      'source_revision' => (int) $item['revision'],
      'source_changed' => $item['changed'],
      'hash_algorithm' => $algorithm,
      'source_hash' => (string) ($item['hash']['entity'] ?? ''),
      'source_translation_hashes' => $received,
      'source_default_langcode' => $default,
      'withheld_translations' => $item['withheld'],
      'local_translations' => array_values(array_diff(
        array_map('strval', array_keys($entity->getTranslationLanguages())),
        array_map(fn (string $langcode) => $this->languageMap->toLocal($langcode), array_keys($origin)),
      )),
      'expected_hash' => (string) ($item['hash']['entity'] ?? ''),
      'import_mode' => 'as_is',
      'notes' => ['adopted' => $this->time->getCurrentTime()],
      'last_event_id' => NULL,
    ];
    if ($differing === []) {
      $this->syncState->save($entity_type_id, $item['uuid'], $fields + [
        'local_hash' => $local->entity,
        'local_translation_hashes' => $local->translations,
        'status' => SyncStatus::SYNCED,
        'last_error' => NULL,
      ]);
      return 'adopted';
    }
    // Compared with the origin's version as its baseline, the copy has
    // edits in the translations that differ.
    $this->syncState->save($entity_type_id, $item['uuid'], $fields + [
      'local_hash' => (string) ($item['hash']['entity'] ?? ''),
      'local_translation_hashes' => $received,
      'status' => SyncStatus::CONFLICT,
      'conflict_at' => $this->time->getCurrentTime(),
      'last_error' => sprintf('Already here when this site was paired, and differs from the origin\'s revision %d (%s). Review it.', $item['revision'], implode(', ', $differing)),
    ]);
    return 'adopted_edited';
  }

}
