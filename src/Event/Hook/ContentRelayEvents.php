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

  /**
   * Before a replica saves an incoming entity, inside the transaction.
   *
   * @Event
   *
   * @see \Drupal\content_relay\Event\Hook\ApplyEvent
   */
  public const PRE_APPLY = 'content_relay.pre_apply';

  /**
   * After a replica saved or deleted an incoming entity, in the transaction.
   *
   * @Event
   *
   * @see \Drupal\content_relay\Event\Hook\ApplyEvent
   */
  public const POST_APPLY = 'content_relay.post_apply';

  /**
   * A replica could not apply a valid event. For alerts.
   *
   * @Event
   *
   * @see \Drupal\content_relay\Event\Hook\ApplyFailedEvent
   */
  public const APPLY_FAILED = 'content_relay.apply_failed';

}
