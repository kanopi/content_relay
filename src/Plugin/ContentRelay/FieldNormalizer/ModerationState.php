<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\FieldNormalizer;

use Drupal\content_relay\Attribute\ContentRelayFieldNormalizer;
use Drupal\content_relay\Serialization\FieldNormalizerBase;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Content Moderation's state, on any moderated entity type.
 *
 * The field is computed from a separate content_moderation_state entity, so
 * it would be left out like other computed fields. It is synced because it is
 * content: a translation published on the origin should be published on the
 * replica, and a draft should stay a draft. The state is set per translation
 * and Content Moderation applies it on save, as when an editor picks it.
 */
#[ContentRelayFieldNormalizer(
  id: 'moderation_state',
  label: new TranslatableMarkup('Moderation state'),
)]
class ModerationState extends FieldNormalizerBase {

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return is_a($definition->getClass(), 'Drupal\content_moderation\Plugin\Field\ModerationStateFieldItemList', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function syncsComputed(): bool {
    return TRUE;
  }

}
