<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\FieldNormalizerBase;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A menu link's parent, when the parent is another content menu link.
 *
 * The value, "menu_link_content:<uuid>", is already portable. What this adds
 * is the dependency: the parent link is sent first and the child waits for
 * it, so the hierarchy is rebuilt with the right parents. Parents that are
 * other kinds of menu link (from a module's links.menu.yml) are plugin IDs
 * that exist on every site with the module, and pass through.
 */
#[ContentRelayFieldNormalizer(
  id: 'menu_link_parent',
  label: new TranslatableMarkup('Menu link parent'),
)]
class MenuLinkParent extends FieldNormalizerBase {

  private const PREFIX = 'menu_link_content:';

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getTargetEntityTypeId() === 'menu_link_content' && $definition->getName() === 'parent';
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeItem(FieldItemInterface $item, array $values, NormalizationContext $context, EntitySerializer $serializer): ?array {
    if ($uuid = $this->uuid($values)) {
      $context->addReference('menu_link_content', $uuid);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalizeItem(array $item, FieldDefinitionInterface $definition, DenormalizationContext $context, EntitySerializer $serializer): ?array {
    $uuid = $this->uuid($item);
    if ($uuid === NULL || $serializer->getUuidResolver()->toId('menu_link_content', $uuid) !== NULL) {
      return $item;
    }
    if ($context->isExternal('menu_link_content', $uuid)) {
      $context->substitute(NULL);
      return NULL;
    }
    $context->addMissingReference('menu_link_content', $uuid, $definition->getName());
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function referencedUuids(array $item, FieldDefinitionInterface $definition): array {
    return ($uuid = $this->uuid($item)) ? ['menu_link_content' => [$uuid]] : [];
  }

  /**
   * The parent link's UUID, when the parent is a content menu link.
   */
  private function uuid(array $values): ?string {
    $value = $values['value'] ?? NULL;
    return is_string($value) && str_starts_with($value, self::PREFIX) ? substr($value, strlen(self::PREFIX)) : NULL;
  }

}
