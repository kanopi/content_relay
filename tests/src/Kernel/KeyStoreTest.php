<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests key storage through the Key module, signing and replay protection.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class KeyStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_relay', 'key', 'system'];

  /**
   * Environment variables set by the test, to unset afterwards.
   *
   * @var string[]
   */
  private array $env = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['content_relay']);
    $this->installSchema('content_relay', ['content_relay_seen_message']);
    // Kernel tests do not run hook_install(); give the site its ID.
    content_relay_install(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->env as $name) {
      putenv($name);
    }
    parent::tearDown();
  }

  /**
   * Stores generated key material in an environment-variable key entity.
   */
  private function createKey(string $id, KeyPurpose $purpose, string $provider = 'env'): KeyPair {
    [$pair, $material] = KeyPair::generate($purpose);
    $variable = 'CONTENT_RELAY_TEST_' . strtoupper($id);
    putenv("$variable=$material");
    $this->env[] = $variable;
    Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => 'authentication',
      'key_provider' => $provider,
      'key_provider_settings' => $provider === 'env'
        ? ['env_variable' => $variable, 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]
        : ['key_value' => $material],
      'key_input' => $provider === 'env' ? 'none' : 'text_field',
    ])->save();
    return $pair;
  }

  /**
   * Sets the key references for a purpose.
   */
  private function setKeys(KeyPurpose $purpose, array $references): void {
    $this->config('content_relay.settings')->set($purpose->settingsKey(), $references)->save();
    $this->container->get('content_relay.key_store')->reset();
  }

  /**
   * The install hook gives the site a UUID.
   */
  public function testSiteIdIsGenerated(): void {
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->container->get('content_relay.site_identity')->getSiteId());
  }

  /**
   * Primary, active and retired keys behave as their status says.
   */
  public function testKeyStatuses(): void {
    $old = $this->createKey('old', KeyPurpose::Signing);
    $new = $this->createKey('new', KeyPurpose::Signing);
    $gone = $this->createKey('gone', KeyPurpose::Signing);
    $this->setKeys(KeyPurpose::Signing, [
      ['key' => 'old', 'status' => 'active'],
      ['key' => 'new', 'status' => 'primary'],
      ['key' => 'gone', 'status' => 'retired'],
    ]);
    $store = $this->container->get('content_relay.key_store');
    $this->assertSame($new->id(), $store->getPrimary(KeyPurpose::Signing)->id());
    $this->assertNotNull($store->get(KeyPurpose::Signing, $old->id()));
    $this->assertNull($store->get(KeyPurpose::Signing, $gone->id()));
    $this->assertSame([$old->id(), $new->id()], array_keys($store->getPublicKeys(KeyPurpose::Signing)));
    $this->assertSame(base64_encode($new->publicKey), $store->getPublicKeys(KeyPurpose::Signing)[$new->id()]['public_key']);
  }

  /**
   * Keys kept in configuration or the database are refused.
   */
  public function testConfigProviderIsRefused(): void {
    $this->createKey('in_config', KeyPurpose::Signing, 'config');
    $this->setKeys(KeyPurpose::Signing, [['key' => 'in_config', 'status' => 'primary']]);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/"config" provider/');
    $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing);
  }

  /**
   * An encryption key in a signing slot is refused.
   */
  public function testWrongPurposeIsRefused(): void {
    $this->createKey('box', KeyPurpose::Encryption);
    $this->setKeys(KeyPurpose::Signing, [['key' => 'box', 'status' => 'primary']]);
    $this->expectException(\RuntimeException::class);
    $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing);
  }

  /**
   * No key exported in configuration ever contains the private key.
   */
  public function testNoSecretInConfig(): void {
    $this->createKey('signing', KeyPurpose::Signing);
    $this->setKeys(KeyPurpose::Signing, [['key' => 'signing', 'status' => 'primary']]);
    $export = json_encode([
      $this->config('content_relay.settings')->getRawData(),
      $this->config('key.key.signing')->getRawData(),
    ]);
    $this->assertStringNotContainsString(KeyPurpose::Signing->materialPrefix(), $export);
  }

  /**
   * A message signed here verifies at its recipient, once, through services.
   */
  public function testSignAndVerifyThroughServices(): void {
    $pair = $this->createKey('signing', KeyPurpose::Signing);
    $this->setKeys(KeyPurpose::Signing, [['key' => 'signing', 'status' => 'primary']]);
    $site_id = $this->container->get('content_relay.site_identity')->getSiteId();

    // The site sends to itself: it is the only peer the test has.
    $this->container->get('content_relay.public_key_resolver')->addResolver(new class($site_id, $pair) implements PublicKeyResolverInterface {

      public function __construct(private string $site, private KeyPair $pair) {}

      /**
       * {@inheritdoc}
       */
      public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string {
        return $site_id === $this->site && $key_id === $this->pair->id() ? $this->pair->publicKey : NULL;
      }

    });
    $envelope = $this->container->get('content_relay.message_signer')->sign($site_id, 'ping', ['hello' => 'world'])->toArray();
    /** @var \Drupal\content_relay\Crypto\MessageVerifier $verifier */
    $verifier = $this->container->get('content_relay.message_verifier');
    $this->assertSame(['hello' => 'world'], $verifier->verify($envelope)->body);

    try {
      $verifier->verify($envelope);
      $this->fail('A replayed message was accepted.');
    }
    catch (VerificationException $e) {
      $this->assertSame(VerificationException::REPLAYED, $e->reason);
    }

    // Records expire, and cron removes them.
    $guard = $this->container->get('content_relay.replay_guard');
    $this->assertSame(1, $guard->prune(\PHP_INT_MAX));
  }

  /**
   * A key entity provider check is available to forms.
   */
  public function testProviderCheck(): void {
    $this->createKey('env_key', KeyPurpose::Signing);
    KeyStore::assertAcceptableProvider(Key::load('env_key'));
    $this->createKey('state_key', KeyPurpose::Signing, 'config');
    $this->expectException(\InvalidArgumentException::class);
    KeyStore::assertAcceptableProvider(Key::load('state_key'));
  }

}
