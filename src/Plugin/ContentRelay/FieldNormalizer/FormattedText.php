<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay\File\FileToken;
use Drupal\content_relay\File\FileUrlMapper;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerBase;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formatted text: files and entities linked or embedded in the markup.
 *
 * - Elements with data-entity-type and data-entity-uuid (CKEditor uploads,
 *   <drupal-media> and other embeds) already name their target portably;
 *   the target becomes a dependency of the item.
 * - src and href attributes pointing at this site's stored files, including
 *   private files and image style derivatives, become file tokens, and the
 *   file a dependency.
 * - Links to other sites are left alone.
 *
 * Every file the text uses is listed with its checksum under FILES, so
 * replacing a linked file changes the item's hash. URLs that look like this
 * site's files but match no file, or a file missing from storage, are left as
 * they are and reported as unresolved.
 *
 * On the receiving side tokens become that site's URLs for its copies (or
 * whatever DenormalizationContext::$fileUrl decides), and hashing turns them
 * back into tokens, so rewritten links never look like a difference.
 */
#[ContentRelayFieldNormalizer(
  id: 'formatted_text',
  label: new TranslatableMarkup('Formatted text'),
)]
class FormattedText extends FieldNormalizerBase implements ContainerFactoryPluginInterface {

  /**
   * The item key listing the files the text uses.
   */
  public const FILES = 'content_relay_files';

  /**
   * Properties holding markup.
   */
  protected const PROPERTIES = ['value', 'summary'];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlMapper $urlMapper,
    protected FileChecksums $checksums,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('content_relay.file_url_mapper'),
      $container->get('content_relay.file_checksums'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return self::itemIs($definition, 'Drupal\text\Plugin\Field\FieldType\TextItemBase');
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    $files = [];
    $field = $item->getFieldDefinition()->getName();
    foreach (self::PROPERTIES as $property) {
      if (is_string($values[$property] ?? NULL) && $values[$property] !== '') {
        $values[$property] = $this->toPortable($values[$property], $context, $field, $files);
      }
    }
    if ($files !== []) {
      ksort($files);
      $values[self::FILES] = array_values($files);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    unset($item[self::FILES]);
    foreach (self::PROPERTIES as $property) {
      if (is_string($item[$property] ?? NULL) && str_contains($item[$property], '{cr-file:')) {
        $item[$property] = $this->toLocal($item[$property], $context, $definition->getName(), $serializer);
      }
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    $uuids = [];
    foreach (self::PROPERTIES as $property) {
      if (is_string($item[$property] ?? NULL)) {
        foreach (FileToken::uuids($item[$property]) as $uuid) {
          $uuids['file'][] = $uuid;
        }
      }
    }
    return $uuids;
  }

  /**
   * Turns this site's file URLs into tokens and collects what is referenced.
   *
   * @param string $html
   *   The markup.
   * @param \Drupal\content_relay\Serialization\NormalizationContext $context
   *   Collects references and unresolved links.
   * @param string $field
   *   The field, for reporting.
   * @param array<string, array{uuid: string, sha256: string|null}> $files
   *   Collects the files used, by UUID.
   */
  protected function toPortable(string $html, NormalizationContext $context, string $field, array &$files): string {
    // Embeds name their target by UUID already.
    if (preg_match_all('/<[a-z][^>]*\sdata-entity-uuid\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', $html, $tags, \PREG_SET_ORDER)) {
      foreach ($tags as $tag) {
        if (!preg_match('/\sdata-entity-type\s*=\s*(["\'])([a-z0-9_]+)\1/i', $tag[0], $type)) {
          continue;
        }
        $entity_type_id = $type[2];
        $uuid = $tag[2];
        if (!$this->isUuidType($entity_type_id)) {
          continue;
        }
        $context->addReference($entity_type_id, $uuid);
        if ($entity_type_id === 'file') {
          $files[$uuid] = ['uuid' => $uuid, 'sha256' => $this->checksumOf($uuid)];
        }
      }
    }

    // Links and sources pointing at stored files.
    return preg_replace_callback('/(\s(?:src|href)\s*=\s*)(["\'])(.*?)\2/is', function (array $m) use ($context, $field, &$files): string {
      $url = html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5);
      if (str_starts_with($url, '{cr-file:')) {
        return $m[0];
      }
      $target = $this->resolve($url, $context);
      if ($target === FALSE) {
        return $m[0];
      }
      if ($target === NULL) {
        $context->unresolved[] = ['field' => $field, 'entity_type' => 'file', 'id' => $url];
        return $m[0];
      }
      [$uuid, $style] = $target;
      $context->addReference('file', $uuid);
      $files[$uuid] = ['uuid' => $uuid, 'sha256' => $this->checksumOf($uuid)];
      return $m[1] . $m[2] . FileToken::build($uuid, $style) . $m[2];
    }, $html) ?? $html;
  }

  /**
   * Which file a URL is.
   *
   * @return array{0: string, 1: string|null}|false|null
   *   The file UUID and image style; FALSE when the URL is not a stored file
   *   of this site at all; NULL when it looks like one but no such file
   *   exists, or its content is missing.
   */
  protected function resolve(string $url, NormalizationContext $context): array|false|null {
    $mapped = $this->urlMapper->toUri($url);
    if ($mapped === NULL) {
      $other = $context->fileUrlResolver ? ($context->fileUrlResolver)($url) : NULL;
      return $other === NULL ? FALSE : [$other['uuid'], $other['style']];
    }
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $mapped['uri']]);
    $file = reset($files);
    if (!$file || $this->checksums->forUri($mapped['uri']) === NULL) {
      return NULL;
    }
    return [(string) $file->uuid(), $mapped['style']];
  }

  /**
   * Turns file tokens into URLs.
   */
  protected function toLocal(string $html, DenormalizationContext $context, string $field, EntitySerializer $serializer): string {
    return preg_replace_callback(FileToken::PATTERN, function (array $m) use ($context, $field, $serializer): string {
      $uuid = $m[1];
      $style = ($m[2] ?? '') === '' ? NULL : $m[2];
      if ($context->fileUrl !== NULL) {
        $url = ($context->fileUrl)($uuid, $style);
      }
      else {
        $id = $serializer->getUuidResolver()->toId('file', $uuid);
        /** @var \Drupal\file\FileInterface|null $file */
        $file = $id === NULL ? NULL : $this->entityTypeManager->getStorage('file')->load($id);
        $url = $file === NULL ? NULL : $this->urlMapper->toUrl($file->getFileUri(), $style);
      }
      if ($url === NULL) {
        $context->addMissingReference('file', $uuid, $field);
        return $m[0];
      }
      return htmlspecialchars($url, \ENT_QUOTES | \ENT_HTML5);
    }, $html) ?? $html;
  }

  /**
   * The checksum of a file by UUID, or NULL when it is not stored.
   */
  protected function checksumOf(string $uuid): ?string {
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $uuid]);
    $file = reset($files);
    return $file ? ($this->checksums->forUri($file->getFileUri())['sha256'] ?? NULL) : NULL;
  }

  /**
   * Whether an entity type is a content type matched by UUID.
   */
  protected function isUuidType(string $entity_type_id): bool {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    return $definition instanceof ContentEntityTypeInterface && $definition->hasKey('uuid');
  }

}
