<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Schema;

use Drupal\content_relay\Schema\SchemaDescriber;
use Drupal\content_relay_replica\Entity\ContentRelayMapping;

/**
 * Compares an origin bundle with this site's, for taking content as it is.
 *
 * Content is taken as it is only when this site has the same bundle with the
 * same synced fields: names, types and cardinality. Anything else is a
 * problem, and the bundle needs a mapping instead. Differences that do not
 * stop it, such as translatability, are warnings.
 */
class CompatibilityChecker {

  public function __construct(
    protected SchemaDescriber $describer,
  ) {}

  /**
   * Compares one origin bundle description with the same bundle here.
   *
   * @return array{problems: string[], warnings: string[]}
   *   Readable differences.
   */
  public function check(string $entity_type_id, string $bundle, array $origin): array {
    $local = $this->describer->describe($entity_type_id, $bundle);
    if ($local === NULL) {
      return ['problems' => [sprintf('This site has no %s bundle "%s".', $entity_type_id, $bundle)], 'warnings' => []];
    }
    $problems = $warnings = [];
    foreach ($origin['fields'] ?? [] as $name => $field) {
      $here = $local['fields'][$name] ?? NULL;
      if ($here === NULL) {
        $problems[] = sprintf('Field "%s" (%s) is missing here.', $name, $field['type']);
        continue;
      }
      if ($here['type'] !== $field['type']) {
        $problems[] = sprintf('Field "%s" is %s on the origin and %s here.', $name, $field['type'], $here['type']);
      }
      if ($here['cardinality'] !== $field['cardinality']) {
        $problems[] = sprintf('Field "%s" holds %s on the origin and %s here.', $name, self::cardinality($field['cardinality']), self::cardinality($here['cardinality']));
      }
      if (($field['target_type'] ?? NULL) !== ($here['target_type'] ?? NULL)) {
        $problems[] = sprintf('Field "%s" references %s on the origin and %s here.', $name, $field['target_type'] ?? 'nothing', $here['target_type'] ?? 'nothing');
      }
      if ($here['translatable'] !== $field['translatable']) {
        $warnings[] = sprintf('Field "%s" is %s on the origin and %s here.', $name, $field['translatable'] ? 'translatable' : 'not translatable', $here['translatable'] ? 'translatable' : 'not translatable');
      }
    }
    foreach ($local['fields'] as $name => $here) {
      if (!isset($origin['fields'][$name])) {
        $message = sprintf('Field "%s" here is not on the origin, so it would always be emptied.', $name);
        $here['required'] ? $problems[] = $message . ' It is required.' : $warnings[] = $message;
      }
    }
    return ['problems' => $problems, 'warnings' => $warnings];
  }

  /**
   * What an origin bundle has that a mapping neither maps nor ignores.
   *
   * @return string[]
   *   Origin field names.
   */
  public function unmappedFields(array $origin, ContentRelayMapping $mapping): array {
    $known = [...$mapping->getMappedSourceFields(), ...$mapping->getIgnoredSourceFields()];
    return array_values(array_diff(array_keys($origin['fields'] ?? []), $known));
  }

  /**
   * Cardinality in words.
   */
  protected static function cardinality(int $cardinality): string {
    return $cardinality === -1 ? 'unlimited values' : ($cardinality === 1 ? 'one value' : $cardinality . ' values');
  }

}
