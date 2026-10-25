<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

/**
 * Where values being transformed come from.
 */
final class TransformContext {

  public function __construct(
    public readonly array $snapshot,
    public readonly string $langcode,
    public readonly string $sourceField,
    public readonly string $targetField,
    protected readonly \Closure $sourceReader,
  ) {}

  /**
   * The values of another source field or property, in the same language.
   *
   * @param string $path
   *   A field ("field_subtitle") or property ("field_subtitle.value").
   *
   * @return array
   *   A list of items or property values.
   */
  public function sourceValues(string $path): array {
    return ($this->sourceReader)($path, $this->langcode);
  }

}
