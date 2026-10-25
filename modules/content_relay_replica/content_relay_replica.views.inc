<?php

/**
 * @file
 * Views integration for Content Relay Replica.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data().
 */
function content_relay_replica_views_data(): array {
  return \Drupal::service('content_relay_replica.sync_state_views_data')->getViewsData();
}
