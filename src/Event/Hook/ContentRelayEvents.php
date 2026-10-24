<?php

declare(strict_types=1);

namespace Drupal\content_relay\Event\Hook;

/**
 * Names of the events other modules can subscribe to.
 */
final class ContentRelayEvents {

  /**
   * Before an entity is normalized.
   *
   * @Event
   *
   * @see \Drupal\content_relay\Event\Hook\SerializeEvent
   */
  public const PRE_SERIALIZE = 'content_relay.pre_serialize';

  /**
   * After an entity is normalized, with the snapshot alterable.
   *
   * @Event
   *
   * @see \Drupal\content_relay\Event\Hook\SerializeEvent
   */
  public const POST_SERIALIZE = 'content_relay.post_serialize';

}
