<?php

/**
 * @file
 * Post update functions for Content Relay Replica.
 */

declare(strict_types=1);

/**
 * Gives a site already paired with an origin its pairing settings.
 */
function content_relay_replica_post_update_origin_pairing(): string {
  $origin = \Drupal::service('content_relay_replica.origin')->get();
  if ($origin === NULL) {
    return (string) t('Not paired; nothing to do.');
  }
  $pairing = \Drupal::service('content_relay_replica.receiving_settings')->ensurePairing($origin['label']);
  return (string) t('Receiving settings for @label are now the pairing %id.', [
    '@label' => $pairing->label(),
    '%id' => $pairing->id(),
  ]);
}
