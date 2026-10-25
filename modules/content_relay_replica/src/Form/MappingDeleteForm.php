<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Deletes a mapping.
 */
class MappingDeleteForm extends EntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('Content of this origin bundle will then be taken as it is, onto the same bundle here, which must have the same fields. Items already synced are not changed.');
  }

}
