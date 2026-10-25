<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Mapping;

/**
 * A mapped import cannot be done; the item is not saved.
 */
class MappingException extends \RuntimeException {}
