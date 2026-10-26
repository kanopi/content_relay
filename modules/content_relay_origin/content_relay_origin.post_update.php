<?php

/**
 * @file
 * Post update functions for Content Relay Origin.
 */

declare(strict_types=1);

/**
 * Gives each replica already paired its pairing, following site-wide settings.
 */
function content_relay_origin_post_update_replica_pairings(): string {
  $sending = \Drupal::service('content_relay_origin.sending_settings');
  $count = 0;
  foreach (\Drupal::service('content_relay_origin.replica_registry')->getAll() as $replica) {
    if ($sending->pairing($replica->id) === NULL) {
      $sending->ensurePairing($replica->id, $replica->label);
      $count++;
    }
  }
  return (string) \Drupal::translation()->formatPlural($count, 'Added a pairing for one replica.', 'Added pairings for @count replicas.');
}
