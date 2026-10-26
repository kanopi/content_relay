<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Functional;

use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests enabling both roles on one site, as a site builder would.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class BothRolesUiTest extends BrowserTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * All three modules at once from the Extend page; both roles then work.
   */
  public function testEnableBothRoles(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer modules', 'administer site configuration']));
    $this->drupalGet('admin/modules');
    $this->submitForm([
      'modules[content_relay][enable]' => TRUE,
      'modules[content_relay_origin][enable]' => TRUE,
      'modules[content_relay_replica][enable]' => TRUE,
    ], 'Install');
    // Key is a dependency, confirmed on a page of its own.
    if ($this->getSession()->getPage()->hasButton('Continue')) {
      $this->submitForm([], 'Continue');
    }
    $this->assertSession()->pageTextContains('modules have been installed');
    $this->rebuildContainer();
    $installed = $this->container->get('module_handler');
    $this->assertTrue($installed->moduleExists('content_relay_origin'));
    $this->assertTrue($installed->moduleExists('content_relay_replica'));

    $this->drupalLogin($this->drupalCreateUser([
      'administer modules',
      'access administration pages',
      'administer content relay origin',
      'change content relay subscription',
      'view content relay status report',
      'view content relay event log',
      'view content relay replica report',
    ]));
    // Each module links to its configuration from the Extend page.
    $this->drupalGet('admin/modules');
    foreach (['content-relay', 'content-relay/origin', 'content-relay/replica'] as $path) {
      $this->assertSession()->linkByHrefExists("admin/config/services/$path");
    }
    // Configuration › Web services › Content Relay lists both roles' pages.
    $this->drupalGet('admin/config/services');
    $this->assertSession()->linkExists('Content Relay');
    $this->drupalGet('admin/config/services/content-relay');
    foreach ([
      'Content Relay origin',
      'Content Relay status',
      'Content Relay event log',
      'Content Relay replica',
      'Content Relay synced items',
    ] as $link) {
      $this->assertSession()->linkExists($link);
    }

    $this->drupalGet('admin/reports/content-relay');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Content Relay status');
    $this->drupalGet('admin/reports/content-relay/received');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Content Relay synced items');

    // Each role's commands, under names of their own.
    $this->drush('list', [], ['filter' => 'content-relay', 'format' => 'json']);
    $commands = array_column(json_decode($this->getOutput(), TRUE)['commands'], 'name');
    foreach (['status', 'reconcile', 'validate'] as $command) {
      $this->assertContains("content-relay:origin-$command", $commands);
      $this->assertContains("content-relay:replica-$command", $commands);
    }
    $this->drush('content-relay:origin-status');
    $this->drush('content-relay:replica-status', [], [], NULL, NULL, 1);
    $this->assertStringContainsString('not paired', strtolower($this->getErrorOutput() . $this->getOutput()));
  }

}
