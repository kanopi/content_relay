<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Whether a synced copy was changed on this replica since it was synced.
 *
 * After every apply the copy is hashed the way the origin hashes, and the
 * result is kept as the item's local hash. Hashing the copy again the same
 * way and comparing tells whether anything synced was edited here since.
 */
class LocalChanges {

  public function __construct(
    protected EntitySerializer $serializer,
    protected RevisionHasher $hasher,
  ) {}

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
   *   differs from what the origin sent, which may be empty when only the
   *   set of translations changed.
   */
  public function editedTranslations(ContentEntityInterface $entity, array $state): ?array {
    if ($state['local_hash'] === NULL) {
      return NULL;
    }
    $source = $state['source_translation_hashes'];
    $source = is_array($source) ? $source : (json_decode((string) $source, TRUE) ?: []);
    $context = new NormalizationContext(array_map('strval', array_keys($source)));
    $hashes = $this->hasher->hash($this->serializer->normalize($entity, $context), [], (int) $state['hash_algorithm']);
    if (hash_equals($state['local_hash'], $hashes->entity)) {
      return NULL;
    }
    $edited = [];
    foreach ($hashes->translations as $langcode => $hash) {
      if (!isset($source[$langcode]) || !hash_equals($source[$langcode], $hash)) {
        $edited[] = (string) $langcode;
      }
    }
    return $edited;
  }

}
