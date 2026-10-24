<?php

declare(strict_types=1);

namespace Drupal\content_relay;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * This site's identity among its peers.
 *
 * The site ID is a UUID generated when the module is installed. Messages are
 * addressed to it and signed with it as sender, so it must be the same on
 * every environment of one site (it travels with config export) and different
 * between the origin and each replica.
 */
class SiteIdentity {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * This site's ID.
   *
   * @throws \RuntimeException
   *   When the module was installed without one.
   */
  public function getSiteId(): string {
    $id = (string) $this->configFactory->get('content_relay.settings')->get('site_id');
    if ($id === '') {
      throw new \RuntimeException('Content Relay has no site ID. Reinstall the module or set content_relay.settings:site_id.');
    }
    return $id;
  }

  /**
   * A human-readable name for this site, for peers' admin screens.
   */
  public function getLabel(): string {
    return (string) ($this->configFactory->get('content_relay.settings')->get('site_label') ?: $this->configFactory->get('system.site')->get('name'));
  }

}
