<?php

declare(strict_types=1);

namespace Drupal\content_relay\Exception;

/**
 * A sync event does not match the event schema.
 */
final class InvalidEventException extends \InvalidArgumentException {}
