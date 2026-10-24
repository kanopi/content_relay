<?php

declare(strict_types=1);

namespace Drupal\content_relay\Hash;

/**
 * The hashes of one revision: the whole entity and each translation.
 *
 * The algorithm version travels with every hash, because a hash is only
 * comparable with another computed the same way. Two hashes with different
 * versions are neither equal nor different; they are not comparable.
 */
final class RevisionHashes {

  /**
   * Constructs a RevisionHashes.
   *
   * @param int $algorithmVersion
   *   The canonical form version these were computed with.
   * @param string $entity
   *   Lowercase hex SHA-256 of the whole entity.
   * @param array<string, string> $translations
   *   Lowercase hex SHA-256 per origin langcode, sorted by langcode.
   */
  public function __construct(
    public readonly int $algorithmVersion,
    public readonly string $entity,
    public readonly array $translations,
  ) {}

  /**
   * Whether these hashes can be compared with others.
   */
  public function isComparableWith(RevisionHashes $other): bool {
    return $this->algorithmVersion === $other->algorithmVersion;
  }

  /**
   * Langcodes whose translation hash differs from, or is missing in, another.
   *
   * @param \Drupal\content_relay\Hash\RevisionHashes $other
   *   The hashes to compare with, usually the other site's.
   * @param string[]|null $langcodes
   *   Only consider these langcodes, for a replica that filters languages. NULL
   *   for all langcodes on either side.
   *
   * @return string[]
   *   The langcodes that are out of sync, sorted.
   */
  public function differingTranslations(RevisionHashes $other, ?array $langcodes = NULL): array {
    if (!$this->isComparableWith($other)) {
      throw new \LogicException(sprintf('Hash algorithm versions %d and %d are not comparable.', $this->algorithmVersion, $other->algorithmVersion));
    }
    $langcodes ??= array_keys($this->translations + $other->translations);
    $differing = [];
    foreach ($langcodes as $langcode) {
      if (($this->translations[$langcode] ?? NULL) !== ($other->translations[$langcode] ?? NULL)) {
        $differing[] = (string) $langcode;
      }
    }
    sort($differing);
    return $differing;
  }

  /**
   * Returns the structure carried in events and stored in hash tables.
   *
   * @return array{algorithm: int, entity: string, translations: array<string, string>}
   *   The hashes.
   */
  public function toArray(): array {
    return [
      'algorithm' => $this->algorithmVersion,
      'entity' => $this->entity,
      'translations' => $this->translations,
    ];
  }

  /**
   * Builds hashes from the structure toArray() returns.
   *
   * @param array $data
   *   The structure, usually from a decoded event.
   *
   * @throws \InvalidArgumentException
   *   When the structure is malformed.
   */
  public static function fromArray(array $data): self {
    $hex = static fn ($value): bool => is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    if (!is_int($data['algorithm'] ?? NULL) || !$hex($data['entity'] ?? NULL) || !is_array($data['translations'] ?? NULL)) {
      throw new \InvalidArgumentException('Revision hashes need an integer algorithm, an entity hash and a translations map.');
    }
    foreach ($data['translations'] as $langcode => $hash) {
      if (!is_string($langcode) || !$hex($hash)) {
        throw new \InvalidArgumentException('Translation hashes must be keyed by langcode and be SHA-256 hex.');
      }
    }
    $translations = $data['translations'];
    ksort($translations, \SORT_STRING);
    return new self($data['algorithm'], $data['entity'], $translations);
  }

}
