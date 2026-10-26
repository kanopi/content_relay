<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Plugin\ContentRelay\Normalizer\GenericContentEntityNormalizer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Follows changes to the languages this site receives.
 *
 * - The origin adds a language: a site receiving every language gets it from
 *   then on. A site receiving selected languages shows it as pending, to add
 *   or ignore; until then nothing in it arrives.
 * - This site starts receiving a language: a backfill run of reconciliation
 *   pulls the translations it missed.
 * - This site stops receiving a language, or the origin removes one: the
 *   translations already here are kept as local translations, left alone
 *   by sync and out of its hashes, or deleted, as chosen. Both are done in a
 *   queue, a chunk of items at a time.
 */
class LanguageSubscription {

  public const QUEUE = 'content_relay_replica_languages';

  /**
   * State: languages the origin added that wait for a decision here.
   */
  public const PENDING = 'content_relay_replica.pending_languages';

  /**
   * State: a backfill is due, for languages this site started receiving.
   */
  public const BACKFILL = 'content_relay_replica.backfill';

  /**
   * State: what to do with translations of languages about to be dropped.
   */
  public const REMOVAL = 'content_relay_replica.language_removal';

  /**
   * Items released per queue item.
   */
  protected const CHUNK = 50;

  public function __construct(
    protected Connection $database,
    protected ContractStore $contracts,
    protected SyncStateStore $syncState,
    protected LocalChanges $localChanges,
    protected LanguageMap $languageMap,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected ReceivingSettings $receiving,
    protected ActivePairing $active,
    protected OriginPeer $origin,
  ) {}

  /**
   * Takes a language notice from the origin.
   *
   * @param array $changes
   *   Each with change (added or removed), langcode and name.
   */
  public function receiveNotice(array $changes): void {
    $pending = $this->pending();
    $terms = $this->contracts->getCurrent()?->terms['replica_terms'] ?? [];
    $ignored = $terms['ignored_languages'] ?? [];
    $all = ($terms['languages']['mode'] ?? SubscriptionContract::LANGUAGES_ALL) === SubscriptionContract::LANGUAGES_ALL;
    foreach ($changes as $change) {
      $langcode = (string) ($change['langcode'] ?? '');
      if ($langcode === '') {
        continue;
      }
      if (($change['change'] ?? NULL) === 'added') {
        $this->logger->notice('The origin added language @langcode@what.', [
          '@langcode' => $langcode,
          '@what' => $all ? '; it is received from now on' : (in_array($langcode, $ignored, TRUE) ? ', which is ignored here' : ', which waits to be added or ignored here'),
        ]);
        if (!$all && !in_array($langcode, $ignored, TRUE)) {
          $pending[$langcode] = (string) ($change['name'] ?? $langcode);
        }
      }
      elseif (($change['change'] ?? NULL) === 'removed') {
        unset($pending[$langcode]);
        $this->logger->notice('The origin removed language @langcode; translations in it here are @mode.', [
          '@langcode' => $langcode,
          '@mode' => $this->removalMode() === 'delete' ? 'deleted' : 'kept as local translations',
        ]);
        foreach ($this->contracts->getCurrent()?->effectiveBundles() ?? [] as $entity_type_id => $bundles) {
          foreach ($bundles as $bundle) {
            $this->release((string) $entity_type_id, (string) $bundle, [$langcode], NULL, $this->removalMode());
          }
        }
      }
    }
    $this->state->set($this->active->stateKey(self::PENDING), $pending);
  }

  /**
   * Languages the origin added that wait for a decision, names by langcode.
   *
   * @return array<string, string>
   *   Pending languages.
   */
  public function pending(): array {
    return $this->state->get($this->active->stateKey(self::PENDING), []);
  }

  /**
   * Replica terms with a pending language added or ignored.
   *
   * The caller proposes them to the origin; the language stops being pending.
   *
   * @param array $terms
   *   The current replica terms.
   * @param string $langcode
   *   The language.
   * @param bool $add
   *   TRUE to start receiving it, FALSE to ignore it for good.
   */
  public function decide(array $terms, string $langcode, bool $add): array {
    if ($add) {
      $terms['languages']['selected'] = array_values(array_unique([
        ...($terms['languages']['selected'] ?? []),
        $langcode,
      ]));
      sort($terms['languages']['selected']);
    }
    else {
      $terms['ignored_languages'] = array_values(array_unique([...($terms['ignored_languages'] ?? []), $langcode]));
    }
    $pending = $this->pending();
    unset($pending[$langcode]);
    $this->state->set($this->active->stateKey(self::PENDING), $pending);
    return $terms;
  }

  /**
   * Sets what happens to translations of languages the next contract drops.
   *
   * @param string $mode
   *   What to do with them: keep or delete.
   */
  public function setRemovalMode(string $mode): void {
    $this->state->set($this->active->stateKey(self::REMOVAL), $mode === 'delete' ? 'delete' : 'keep');
  }

  /**
   * Acts on the languages a new contract adds or drops.
   */
  public function contractChanged(?SubscriptionContract $previous, SubscriptionContract $contract): void {
    $mode = $this->removalMode();
    $this->state->delete($this->active->stateKey(self::REMOVAL));
    $backfill = FALSE;
    foreach ($contract->effectiveBundles() as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        // A bundle new to the contract has nothing here to change.
        if ($previous === NULL || !$previous->allowsBundle($entity_type_id, $bundle)) {
          continue;
        }
        $before = $previous->languagesFor($entity_type_id, $bundle);
        $after = $contract->languagesFor($entity_type_id, $bundle);
        // Fewer languages: translations in the others stop syncing.
        if ($after !== NULL && ($before === NULL || array_diff($before, $after) !== [])) {
          $this->release((string) $entity_type_id, (string) $bundle, NULL, $after, $mode);
        }
        // More languages: what exists in them is pulled.
        if ($before !== NULL && ($after === NULL || array_diff($after, $before) !== [])) {
          $backfill = TRUE;
        }
      }
    }
    if ($backfill) {
      $this->state->set($this->active->stateKey(self::BACKFILL), TRUE);
      $this->logger->notice('Contract version @version adds languages; a backfill will pull existing translations in them.', ['@version' => $contract->version()]);
    }
  }

  /**
   * Queues releasing translations of a bundle from sync.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $bundle
   *   Bundle.
   * @param string[]|null $langcodes
   *   Origin langcodes to release, or NULL for those outside $wanted.
   * @param string[]|null $wanted
   *   With $langcodes NULL: the languages still received.
   * @param string $mode
   *   What to do with them: keep or delete.
   */
  public function release(string $entity_type_id, string $bundle, ?array $langcodes, ?array $wanted, string $mode): void {
    $this->queueFactory->get(self::QUEUE)->createItem([
      'entity_type' => $entity_type_id,
      'bundle' => $bundle,
      'langcodes' => $langcodes,
      'wanted' => $wanted,
      'mode' => $mode,
      'after' => '',
      'pairing' => $this->active->id(),
    ]);
  }

  /**
   * Queues moving synced translations from one local langcode to another.
   *
   * For a changed language code mapping: existing translations move to the
   * new code before events use it. Run it after saving the new mapping.
   */
  public function move(string $from, string $to): void {
    foreach ($this->contracts->getCurrent()?->effectiveBundles() ?? [] as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle) {
        $this->queueFactory->get(self::QUEUE)->createItem([
          'entity_type' => (string) $entity_type_id,
          'bundle' => (string) $bundle,
          'move' => ['from' => $from, 'to' => $to],
          'after' => '',
          'pairing' => $this->active->id(),
        ]);
      }
    }
    $this->logger->notice('Moving synced translations from @from to @to.', ['@from' => $from, '@to' => $to]);
  }

  /**
   * Releases or moves a chunk of items. For the queue worker.
   *
   * @return array|null
   *   The queue item for the next chunk, or NULL when the bundle is done.
   */
  public function processQueued(array $item): ?array {
    $uuids = $this->database->select(SyncStateStore::TABLE, 's')
      ->fields('s', ['uuid'])
      ->condition('entity_type', $item['entity_type'])
      ->condition('bundle', $item['bundle'])
      ->condition('source_site', (string) $this->origin->getSiteId())
      ->condition('uuid', $item['after'], '>')
      ->orderBy('uuid')
      ->range(0, self::CHUNK)
      ->execute()
      ->fetchCol();
    foreach ($uuids as $uuid) {
      isset($item['move'])
        ? $this->moveItem($item['entity_type'], (string) $uuid, $item['move']['from'], $item['move']['to'])
        : $this->releaseItem($item['entity_type'], (string) $uuid, $item['langcodes'], $item['wanted'], $item['mode']);
    }
    return count($uuids) === self::CHUNK ? ['after' => (string) end($uuids)] + $item : NULL;
  }

  /**
   * Releases translations of one item.
   */
  protected function releaseItem(string $entity_type_id, string $uuid, ?array $langcodes, ?array $wanted, string $mode): void {
    $state = $this->syncState->get($entity_type_id, $uuid);
    if ($state === NULL || ($state['import_mode'] ?? 'as_is') === 'mapped') {
      return;
    }
    $received = array_map('strval', array_keys($state['source_translation_hashes']));
    $released = $langcodes === NULL
      ? array_values(array_filter($received, fn (string $langcode) => !GenericContentEntityNormalizer::wanted($langcode, $wanted)))
      : array_values(array_intersect($received, $langcodes));
    if ($released === []) {
      return;
    }
    $entity = $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    $edited = $entity === NULL ? NULL : $this->localChanges->editedTranslations($entity, $state);
    $local = array_map(fn (string $langcode) => $this->languageMap->toLocal($langcode), $released);

    if ($mode === 'delete' && $entity !== NULL) {
      $removed = FALSE;
      foreach ($local as $langcode) {
        // The default translation is the entity itself.
        if ($entity->hasTranslation($langcode) && $entity->getUntranslated()->language()->getId() !== $langcode) {
          $entity->removeTranslation($langcode);
          $removed = TRUE;
        }
      }
      if ($removed) {
        $entity->setSyncing(TRUE);
        if ($entity->getEntityType()->isRevisionable()) {
          $entity->setNewRevision(TRUE);
          if ($entity instanceof RevisionLogInterface) {
            $entity->setRevisionLogMessage(sprintf('Removed translations no longer received from the origin: %s', implode(', ', $local)));
            $entity->setRevisionCreationTime($this->time->getCurrentTime());
          }
        }
        $entity->save();
      }
    }

    $after = [
      'source_translation_hashes' => array_diff_key($state['source_translation_hashes'], array_flip($released)),
      'local_translation_hashes' => array_diff_key($state['local_translation_hashes'], array_flip($released)),
      'local_translations' => $mode === 'delete' ? $state['local_translations'] : array_values(array_unique([
        ...$state['local_translations'],
        ...$local,
      ])),
    ] + $state;
    $fields = array_intersect_key($after, array_flip([
      'source_translation_hashes',
      'local_translation_hashes',
      'local_translations',
    ]));
    // Without edits elsewhere, the copy as it is now is in sync.
    if ($entity !== NULL && $edited === NULL) {
      $fields += $this->localChanges->baseline($entity, $after);
      $fields['expected_hash'] = $fields['local_hash'];
    }
    $this->syncState->save($entity_type_id, $uuid, $fields + ['last_synced' => (int) $state['last_synced']]);
  }

  /**
   * Moves one item's translation to another langcode.
   */
  protected function moveItem(string $entity_type_id, string $uuid, string $from, string $to): void {
    $state = $this->syncState->get($entity_type_id, $uuid);
    $entity = $state === NULL ? NULL : $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    if ($entity === NULL || !$entity->hasTranslation($from) || $entity->hasTranslation($to)) {
      return;
    }
    $edited = $this->localChanges->editedTranslations($entity, $state);
    $source = $entity->getTranslation($from);
    if ($entity->getUntranslated()->language()->getId() === $from) {
      // The default translation changes language in place.
      $langcode_key = (string) $entity->getEntityType()->getKey('langcode');
      $entity->getUntranslated()->set($langcode_key, $to);
    }
    else {
      $values = [];
      foreach ($source->getTranslatableFields(FALSE) as $name => $items) {
        $values[$name] = $items->getValue();
      }
      $entity->removeTranslation($from);
      $entity->addTranslation($to, $values);
    }
    $entity->setSyncing(TRUE);
    if ($entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionLogMessage(sprintf('Moved the %s translation to %s, for a new language code mapping.', $from, $to));
        $entity->setRevisionCreationTime($this->time->getCurrentTime());
      }
    }
    $entity->save();
    // In the origin's terms nothing changed.
    $fields = ['last_synced' => (int) $state['last_synced']];
    if ($edited === NULL) {
      $fields += $this->localChanges->baseline($entity, $state);
    }
    $this->syncState->save($entity_type_id, $uuid, $fields);
  }

  /**
   * Keeps the list of an item's local translations current.
   */
  public function refreshLocalTranslations(string $entity_type_id, string $uuid): void {
    $state = $this->syncState->get($entity_type_id, $uuid);
    $entity = $state === NULL ? NULL : $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    if ($entity === NULL) {
      return;
    }
    $synced = array_map(fn (string $langcode) => $this->languageMap->toLocal($langcode), [
      ...array_map('strval', array_keys($state['source_translation_hashes'])),
      ...$state['withheld_translations'],
    ]);
    $local = array_values(array_diff(array_map('strval', array_keys($entity->getTranslationLanguages())), $synced));
    if ($local !== $state['local_translations']) {
      $this->syncState->save($entity_type_id, $uuid, [
        'local_translations' => $local,
        'last_synced' => (int) $state['last_synced'],
      ]);
    }
  }

  /**
   * What to do with translations of dropped languages.
   */
  protected function removalMode(): string {
    return $this->state->get($this->active->stateKey(self::REMOVAL)) ?? ($this->receiving->get('languages.removed') ?? 'keep');
  }

}
