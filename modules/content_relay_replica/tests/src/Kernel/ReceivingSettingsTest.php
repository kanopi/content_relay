<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests receiving settings kept per origin pairing.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReceivingSettingsTest extends ReplicaTestBase {

  /**
   * A pairing's settings win; what it leaves out is site-wide.
   */
  public function testPairingSettings(): void {
    $receiving = $this->container->get('content_relay_replica.receiving_settings');
    $this->assertNull($receiving->pairing());
    $this->assertSame('overwrite', $receiving->get('conflicts.on_event'));

    $pairing = $receiving->ensurePairing('Origin');
    $this->assertSame(ContentRelayOriginPairing::DEFAULT, $pairing->id());
    $this->assertSame($receiving->defaults(), $pairing->getSettings());
    $this->assertSame($pairing->id(), $receiving->ensurePairing('Again')->id(), 'Made once.');

    // The pairing keeps edits here: the origin's update is refused.
    $pairing->setSetting('conflicts.on_event', 'reject')->save();
    $this->assertSame('reject', $receiving->get('conflicts.on_event'));
    $snapshot = $this->originArticle('Before');
    $this->apply($this->event($snapshot, Operation::Create, 1));
    $this->local($snapshot['uuid'])->setTitle('Edited here')->save();
    $result = $this->apply($this->event($this->retitle($snapshot, 'From the origin'), Operation::Update, 2));
    $this->assertSame(ApplyResult::CONFLICT, $result->result);
    $this->assertSame('Edited here', $this->local($snapshot['uuid'])->label());

    // A setting the pairing leaves out follows the site-wide one, as do the
    // settings that are not about one origin, which a pairing cannot have.
    $settings = $pairing->getSettings();
    unset($settings['detach']);
    $pairing->set('settings', $settings)->save();
    $this->config('content_relay_replica.settings')->set('detach.follow_deletes', TRUE)->save();
    $this->assertTrue($receiving->get('detach.follow_deletes'));
    $this->config('content_relay_replica.settings')->set('processed_retention_days', 30)->save();
    $this->assertSame(30, $receiving->get('processed_retention_days'));
  }

  /**
   * A site paired before pairings existed gets one on update.
   */
  public function testUpdate(): void {
    require_once __DIR__ . '/../../../content_relay_replica.post_update.php';
    $this->container->get('module_handler')->loadInclude('content_relay_replica', 'install');
    content_relay_replica_update_10002();
    content_relay_replica_post_update_origin_pairing();
    $pairing = $this->container->get('content_relay_replica.receiving_settings')->pairing();
    $this->assertSame([ContentRelayOriginPairing::DEFAULT, 'Origin'], [$pairing->id(), $pairing->label()]);
  }

  /**
   * The synced items report gains its origin column and filter on update.
   */
  public function testReportUpdate(): void {
    $this->enableModules(['views']);
    $this->installConfig(['content_relay_replica']);
    $config = $this->config('views.view.content_relay_replica_report');
    $options = 'display.default.display_options';
    $config->clear("$options.fields.source_site")
      ->clear("$options.filters.source_site")
      ->clear("$options.style.options.columns.source_site")
      ->clear("$options.style.options.info.source_site")
      ->save();

    $this->container->get('module_handler')->loadInclude('content_relay_replica', 'install');
    $this->assertSame('The synced items report shows and filters by origin.', content_relay_replica_update_10005());
    $config = $this->config('views.view.content_relay_replica_report');
    $fields = array_keys($config->get("$options.fields"));
    $this->assertSame('source_site', $fields[array_search('bundle', $fields, TRUE) + 1]);
    $this->assertSame('origin', $config->get("$options.filters.source_site.expose.identifier"));
    $this->assertSame('source_site', $config->get("$options.style.options.columns.source_site"));
    $this->assertSame('Nothing to do.', content_relay_replica_update_10005());
  }

}
