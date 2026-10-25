<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica;

/**
 * Pairing, a subscription change or a read from the origin failed.
 *
 * The message is for administrators.
 */
class SubscriptionException extends \RuntimeException {}
