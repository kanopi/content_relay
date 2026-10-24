<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\Normalizer;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Comments: everything but the thread position and the poster's address.
 *
 * The thread string ("01.02/") is worked out by each site from the comments
 * it has, so it is rebuilt on save rather than copied. The poster's IP
 * address is personal data that the content does not need.
 */
#[ContentRelayNormalizer(
  id: 'comment',
  label: new TranslatableMarkup('Comment'),
  entity_types: ['comment'],
)]
class Comment extends GenericContentEntityNormalizer {

  /**
   * {@inheritdoc}
   */
  public function getLocalFieldNames(ContentEntityTypeInterface $entity_type): array {
    return [...parent::getLocalFieldNames($entity_type), 'thread', 'hostname'];
  }

}
