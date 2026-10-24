<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Apply;

/**
 * Thrown by code that knows its failure is temporary, to have it retried.
 *
 * For example a file system that is briefly unavailable. Throw it from a hook
 * or subscriber during apply, or wrap the real cause in it.
 */
final class TransientApplyException extends \RuntimeException {}
