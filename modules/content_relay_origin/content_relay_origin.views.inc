<?php

/**
 * @file
 * Views integration for Content Relay Origin.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data().
 */
function content_relay_origin_views_data(): array {
  return \Drupal::service('content_relay_origin.report_views_data')->getViewsData();
}
