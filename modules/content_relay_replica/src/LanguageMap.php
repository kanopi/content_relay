<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

use Drupal\content_relay\Serialization\Snapshot;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Turns the origin's langcodes into this site's, and back.
 *
 * A replica can keep a language under another code (zh-hans on the origin,
 * zh-cn here). Snapshots are mapped to this site's codes before they are
 * applied, and copies are mapped back to the origin's before they are
 * hashed, so hashes always use the origin's codes and compare equal.
 */
class LanguageMap {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LanguageManagerInterface $languageManager,
    protected ReceivingSettings $receiving,
  ) {}

  /**
   * The mapping, origin langcode => local langcode.
   *
   * @return array<string, string>
   *   Only the codes that differ.
   */
  public function all(): array {
    $map = [];
    foreach ($this->receiving->get('languages.map') ?? [] as $entry) {
      if (is_string($entry['origin'] ?? NULL) && is_string($entry['local'] ?? NULL) && $entry['origin'] !== $entry['local']) {
        $map[$entry['origin']] = $entry['local'];
      }
    }
    return $map;
  }

  /**
   * An origin langcode as this site's.
   */
  public function toLocal(string $langcode): string {
    return $this->all()[$langcode] ?? $langcode;
  }

  /**
   * One of this site's langcodes as the origin's.
   */
  public function toOrigin(string $langcode): string {
    return array_flip($this->all())[$langcode] ?? $langcode;
  }

  /**
   * Whether this site can hold a translation in one of its langcodes.
   */
  public function exists(string $local_langcode): bool {
    return in_array($local_langcode, [
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      LanguageInterface::LANGCODE_NOT_APPLICABLE,
    ], TRUE)
      || $this->languageManager->getLanguage($local_langcode) !== NULL;
  }

  /**
   * A snapshot with its langcodes changed, embedded entities included.
   *
   * @param array $snapshot
   *   The snapshot.
   * @param callable(string): string $map
   *   Maps one langcode.
   */
  public static function rename(array $snapshot, callable $map): array {
    $snapshot['default_langcode'] = $map($snapshot['default_langcode']);
    $translations = [];
    foreach ($snapshot['translations'] as $langcode => $fields) {
      $translations[$map((string) $langcode)] = self::renameFields($fields, $map);
    }
    $snapshot['translations'] = $translations;
    $snapshot['shared'] = self::renameFields($snapshot['shared'], $map);
    if (isset($snapshot['metadata']['translations']) && is_array($snapshot['metadata']['translations'])) {
      $metadata = [];
      foreach ($snapshot['metadata']['translations'] as $langcode => $fields) {
        $metadata[$map((string) $langcode)] = $fields;
      }
      $snapshot['metadata']['translations'] = $metadata;
    }
    return $snapshot;
  }

  /**
   * Field items with the snapshots of embedded entities renamed.
   */
  protected static function renameFields(array $fields, callable $map): array {
    foreach ($fields as $name => $items) {
      foreach ($items as $delta => $item) {
        if (isset($item[Snapshot::EMBEDDED]) && is_array($item[Snapshot::EMBEDDED]) && isset($item[Snapshot::EMBEDDED]['translations'])) {
          $fields[$name][$delta][Snapshot::EMBEDDED] = self::rename($item[Snapshot::EMBEDDED], $map);
        }
      }
    }
    return $fields;
  }

  /**
   * A snapshot in this site's langcodes.
   */
  public function snapshotToLocal(array $snapshot): array {
    return $this->all() === [] ? $snapshot : self::rename($snapshot, fn (string $langcode) => $this->toLocal($langcode));
  }

  /**
   * A snapshot in the origin's langcodes.
   */
  public function snapshotToOrigin(array $snapshot): array {
    return $this->all() === [] ? $snapshot : self::rename($snapshot, fn (string $langcode) => $this->toOrigin($langcode));
  }

}
