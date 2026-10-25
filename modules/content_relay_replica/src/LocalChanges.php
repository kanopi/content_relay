<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Hash\RevisionHashes;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Hashes synced copies, and tells whether one was changed on this replica.
 *
 * After every apply the copy is hashed the way the origin hashes, leaving out
 * this site's local-only fields, and the result is kept as the item's local
 * hash. Hashing the copy again the same way and comparing tells whether
 * anything synced was edited here since. Every place that hashes a local copy
 * goes through here, so they all agree.
 */
class LocalChanges {

  public function __construct(
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
    protected LocalOnlyFields $localOnly,
    protected Connection $database,
    protected OriginPeer $origin,
    protected ConfigFactoryInterface $configFactory,
    protected EntityRepositoryInterface $entityRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Hashes a local copy.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The copy.
   * @param string[]|null $langcodes
   *   The origin langcodes it receives, or NULL for all its translations, as
   *   for a mapped copy, which is hashed whole in its own structure.
   * @param int $algorithm
   *   The hash algorithm version.
   */
  public function hash(ContentEntityInterface $entity, ?array $langcodes, int $algorithm = RevisionHasher::CURRENT_VERSION): RevisionHashes {
    $context = new NormalizationContext($langcodes);
    $context->fileUrlResolver = $this->originUrlResolver();
    $local_only = $this->localOnly->forBundle($entity->getEntityTypeId(), $entity->bundle());
    return $this->hasher->hash($this->serializer->normalize($entity, $context), $local_only, $algorithm);
  }

  /**
   * Hashes a local copy as its sync state says it was hashed when applied.
   */
  public function hashFor(ContentEntityInterface $entity, array $state): RevisionHashes {
    $langcodes = ($state['import_mode'] ?? 'as_is') === 'mapped' ? NULL : array_map('strval', array_keys($state['source_translation_hashes']));
    return $this->hash($entity, $langcodes, (int) $state['hash_algorithm']);
  }

  /**
   * The translations edited locally since the last sync, if any.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The local copy, as stored.
   * @param array $state
   *   Its sync state row.
   *
   * @return string[]|null
   *   NULL if the copy is as synced. Otherwise the langcodes whose content
   *   differs from the copy as synced, which may be empty when only the set
   *   of translations changed.
   */
  public function editedTranslations(ContentEntityInterface $entity, array $state): ?array {
    if ($state['local_hash'] === NULL) {
      return NULL;
    }
    $hashes = $this->hashFor($entity, $state);
    if (hash_equals($state['local_hash'], $hashes->entity)) {
      return NULL;
    }
    // Rows from before local translation hashes were kept compare with the
    // origin's, which only differ where this site substituted something.
    $before = $state['local_translation_hashes'] ?: $state['source_translation_hashes'];
    $edited = [];
    foreach ($hashes->translations as $langcode => $hash) {
      if (!isset($before[$langcode]) || !hash_equals($before[$langcode], $hash)) {
        $edited[] = (string) $langcode;
      }
    }
    return $edited;
  }

  /**
   * Sync state fields that make a copy's current content its baseline.
   *
   * For when a user accepts that the origin's version will replace local
   * edits: compared with the baseline, the copy has no edits, so the
   * origin's version is applied without being taken for a conflict.
   *
   * @return array
   *   The local hash fields to save.
   */
  public function baseline(ContentEntityInterface $entity, array $state): array {
    $hashes = $this->hashFor($entity, $state);
    return ['local_hash' => $hashes->entity, 'local_translation_hashes' => $hashes->translations];
  }

  /**
   * The local copy of a synced item, wherever its mapping put it.
   *
   * @param string $entity_type_id
   *   The origin entity type.
   * @param string $uuid
   *   The UUID.
   * @param array $state
   *   The item's sync state.
   */
  public function localCopy(string $entity_type_id, string $uuid, array $state): ?ContentEntityInterface {
    if (($state['import_mode'] ?? 'as_is') === 'mapped' && $state['mapping_id'] !== NULL) {
      $mapping = $this->entityTypeManager->getStorage('content_relay_mapping')->load($state['mapping_id']);
      if ($mapping instanceof ContentRelayMapping) {
        $entity_type_id = $mapping->getTargetEntityType();
      }
    }
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Maps the origin's file URLs back to files, when links point there.
   *
   * Text that links to the origin's copy of a file hashes as the file's UUID,
   * as it does on the origin.
   *
   * @return (\Closure(string): (array{uuid: string, style: string|null}|null))|null
   *   For NormalizationContext::$fileUrlResolver; NULL when links are local.
   */
  public function originUrlResolver(): ?\Closure {
    $origin = $this->origin->get();
    if ($this->configFactory->get('content_relay_replica.settings')->get('files.links') !== 'origin' || $origin === NULL) {
      return NULL;
    }
    $base = rtrim($origin['url'], '/');
    return function (string $url) use ($base): ?array {
      if (!str_starts_with($url, $base . '/')) {
        return NULL;
      }
      $path = (string) parse_url(substr($url, strlen($base)), PHP_URL_PATH);
      $style = NULL;
      if (preg_match('#^(.*/)styles/([a-z0-9_]+)/(public|private)/(.+)$#', $path, $m)) {
        $style = $m[2];
        $path = ($m[3] === 'private' ? '/system/files/' : $m[1]) . $m[4];
      }
      $uuid = $this->database->select(FileSync::TABLE, 'f')
        ->fields('f', ['uuid'])
        ->condition('url', $path)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      return $uuid === FALSE ? NULL : ['uuid' => (string) $uuid, 'style' => $style];
    };
  }

}
