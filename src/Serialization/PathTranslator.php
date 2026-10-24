<?php

declare(strict_types=1);

namespace Drupal\content_relay\Serialization;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Makes paths and URIs that contain entity IDs portable, and back.
 *
 * Links, menu links, path aliases and redirects store things like
 * "entity:node/5", "internal:/node/5/edit", "/taxonomy/term/3" or
 * "route:entity.node.canonical;node=5". The ID in each is local. Here it is
 * swapped for a token naming the entity by UUID, "{cr:node:<uuid>}", and back
 * again on the receiving site. Everything around the ID (the rest of the path,
 * a query string, a fragment) is kept as it is.
 *
 * Which paths belong to which entity type is read from every content entity
 * type's link templates, so it works for any entity type, not a fixed list.
 * A path segment that is not the ID of an existing entity ("/node/add") is
 * left alone.
 */
class PathTranslator {

  /**
   * Matches a portable token.
   */
  public const TOKEN = '/\{cr:([a-z0-9_]+):([0-9a-zA-Z\-]+)\}/';

  /**
   * Path prefixes that precede an entity ID, by entity type.
   *
   * @var array<string, string[]>|null
   */
  protected ?array $prefixes = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UuidResolver $uuidResolver,
  ) {}

  /**
   * Replaces local entity IDs in a path or URI with portable tokens.
   *
   * @param string $value
   *   The path or URI.
   * @param \Drupal\content_relay\Serialization\NormalizationContext|null $context
   *   Collects the entities referenced, as dependencies.
   */
  public function toPortable(string $value, ?NormalizationContext $context = NULL): string {
    $replace = function (string $entity_type_id, string $id) use ($context): ?string {
      $uuid = $this->uuidResolver->toUuid($entity_type_id, $id);
      if ($uuid === NULL) {
        return NULL;
      }
      $context?->addReference($entity_type_id, $uuid);
      return '{cr:' . $entity_type_id . ':' . $uuid . '}';
    };

    // entity:node/5.
    if (preg_match('#^entity:([a-z0-9_]+)/([^/?\#]+)(.*)$#s', $value, $m) && $this->isUuidType($m[1])) {
      return ($token = $replace($m[1], $m[2])) === NULL ? $value : 'entity:' . $m[1] . '/' . $token . $m[3];
    }
    // route:entity.node.canonical;node=5.
    if (preg_match('#^route:entity\.([a-z0-9_]+)\.[^;]*;(.*)$#s', $value, $m) && $this->isUuidType($m[1])) {
      $type = $m[1];
      return preg_replace_callback('#(^|[;&])(' . preg_quote($type, '#') . ')=([^;&]+)#', fn ($p) => $p[1] . $p[2] . '=' . ($replace($type, urldecode($p[3])) ?? $p[3]), $value) ?? $value;
    }
    // internal:/node/5, /node/5, or base:node/5.
    if (preg_match('#^(internal:|base:)?(/?)(.*)$#s', $value, $m)) {
      [, $scheme, $slash, $path] = $m;
      foreach ($this->getPrefixes() as $entity_type_id => $prefixes) {
        foreach ($prefixes as $prefix) {
          if (preg_match('#^' . preg_quote($prefix, '#') . '([^/?\#]+)(.*)$#s', $path, $p)) {
            $token = $replace($entity_type_id, $p[1]);
            if ($token !== NULL) {
              return $scheme . $slash . $prefix . $token . $p[2];
            }
          }
        }
      }
    }
    return $value;
  }

  /**
   * Replaces portable tokens with local IDs.
   *
   * @param string $value
   *   The portable path or URI.
   * @param \Drupal\content_relay\Serialization\DenormalizationContext|null $context
   *   Collects entities that do not exist here.
   * @param string $field
   *   The field, for reporting.
   *
   * @return string|null
   *   The local value, or NULL when a referenced entity does not exist here.
   */
  public function toLocal(string $value, ?DenormalizationContext $context = NULL, string $field = ''): ?string {
    $missing = FALSE;
    $local = preg_replace_callback(self::TOKEN, function (array $m) use ($context, $field, &$missing): string {
      $id = $this->uuidResolver->toId($m[1], $m[2]);
      if ($id === NULL) {
        $missing = TRUE;
        $context?->addMissingReference($m[1], $m[2], $field);
        return $m[0];
      }
      return (string) $id;
    }, $value);
    return $missing ? NULL : $local;
  }

  /**
   * Entities named by tokens in a portable value.
   *
   * @return array<string, string[]>
   *   UUIDs by entity type.
   */
  public function tokens(string $value): array {
    $found = [];
    if (preg_match_all(self::TOKEN, $value, $matches, \PREG_SET_ORDER)) {
      foreach ($matches as $m) {
        $found[$m[1]][] = $m[2];
      }
    }
    return $found;
  }

  /**
   * Path prefixes before each content entity type's ID, longest first.
   *
   * From every link template whose first placeholder is the entity itself:
   * "/node/{node}/edit" gives "node/".
   *
   * @return array<string, string[]>
   *   Prefixes without a leading slash, by entity type.
   */
  protected function getPrefixes(): array {
    if ($this->prefixes !== NULL) {
      return $this->prefixes;
    }
    $prefixes = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasKey('uuid')) {
        continue;
      }
      foreach ($definition->getLinkTemplates() as $template) {
        $position = strpos($template, '{' . $id . '}');
        if ($position === FALSE || str_contains(substr($template, 0, $position), '{')) {
          continue;
        }
        $prefix = ltrim(substr($template, 0, $position), '/');
        if ($prefix !== '') {
          $prefixes[$id][$prefix] = $prefix;
        }
      }
    }
    foreach ($prefixes as &$list) {
      usort($list, fn ($a, $b) => strlen($b) <=> strlen($a));
    }
    return $this->prefixes = $prefixes;
  }

  /**
   * Whether an entity type is a content type with a UUID.
   */
  protected function isUuidType(string $entity_type_id): bool {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    return $definition instanceof ContentEntityTypeInterface && $definition->hasKey('uuid');
  }

}
