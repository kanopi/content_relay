<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\Core\Form\FormState;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests keys held by the Key module, signing and replay protection.
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
   * Creates a Content Relay key whose value is in an environment variable.
   *
   * @param string $id
   *   Key entity ID.
   * @param \Drupal\content_relay\Key\KeyPurpose $purpose
   *   The key type to create.
   * @param string $status
   *   Primary, active or retired.
   * @param string $provider
   *   The key provider.
   * @param \Drupal\content_relay\Key\KeyPurpose|null $material
   *   Generate material for another purpose, to test mismatches.
   */
  private function createKey(string $id, KeyPurpose $purpose, string $status = KeyStore::PRIMARY, string $provider = 'env', ?KeyPurpose $material = NULL): KeyPair {
    [$pair, $value] = KeyPair::generate($material ?? $purpose);
    $variable = 'CONTENT_RELAY_TEST_' . strtoupper($id);
    putenv("$variable=$value");
    $this->env[] = $variable;
    Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => $purpose->keyTypeId(),
      'key_type_settings' => ['status' => $status],
      'key_provider' => $provider,
      'key_provider_settings' => $provider === 'env'
        ? ['env_variable' => $variable, 'base64_encoded' => FALSE, 'strip_line_breaks' => TRUE]
        : ['key_value' => $value],
      'key_input' => $provider === 'env' ? 'none' : 'text_field',
    ])->save();
    $this->container->get('content_relay.key_store')->reset();
    return $pair;
  }

  /**
   * The install hook gives the site a UUID.
   */
  public function testSiteIdIsGenerated(): void {
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->container->get('content_relay.site_identity')->getSiteId());
  }

  /**
   * Keys are found by type, and behave as their status says.
   */
  public function testKeyStatuses(): void {
    $old = $this->createKey('old', KeyPurpose::Signing, KeyStore::ACTIVE);
    $new = $this->createKey('new', KeyPurpose::Signing, KeyStore::PRIMARY);
    $gone = $this->createKey('gone', KeyPurpose::Signing, KeyStore::RETIRED);
    $box = $this->createKey('box', KeyPurpose::Encryption, KeyStore::PRIMARY);
    $store = $this->container->get('content_relay.key_store');

    $this->assertSame($new->id(), $store->getPrimary(KeyPurpose::Signing)->id());
    $this->assertNotNull($store->get(KeyPurpose::Signing, $old->id()));
    $this->assertNull($store->get(KeyPurpose::Signing, $gone->id()));
    $this->assertNull($store->get(KeyPurpose::Signing, $box->id()), 'Purposes never mix.');
    $this->assertEqualsCanonicalizing([$old->id(), $new->id()], array_keys($store->getPublicKeys(KeyPurpose::Signing)));
    $this->assertSame(base64_encode($new->publicKey), $store->getPublicKeys(KeyPurpose::Signing)[$new->id()]['public_key']);
    $this->assertSame($box->id(), $store->getPrimary(KeyPurpose::Encryption)->id());

    // A retired key is not read at all, so losing its value breaks nothing.
    putenv('CONTENT_RELAY_TEST_GONE');
    $store->reset();
    $this->assertSame($new->id(), $store->getPrimary(KeyPurpose::Signing)->id());
  }

  /**
   * Keys of other types are not Content Relay's business.
   */
  public function testOtherKeyTypesAreIgnored(): void {
    $this->createKey('signing', KeyPurpose::Signing);
    Key::create([
      'id' => 'api',
      'label' => 'API',
      'key_type' => 'authentication',
      'key_provider' => 'env',
      'key_provider_settings' => ['env_variable' => 'NOPE'],
    ])->save();
    $this->assertCount(1, $this->container->get('content_relay.key_store')->getPublicKeys(KeyPurpose::Signing));
  }

  /**
   * Without a primary key there is nothing to sign with.
   */
  public function testNoPrimary(): void {
    $this->createKey('only', KeyPurpose::Signing, KeyStore::ACTIVE);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/No primary Content Relay signing key/');
    $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing);
  }

  /**
   * Keys kept in configuration or the database are refused at runtime.
   */
  public function testConfigProviderIsRefused(): void {
    $this->createKey('in_config', KeyPurpose::Signing, KeyStore::PRIMARY, 'config');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/"config" provider/');
    $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing);
  }

  /**
   * An encryption key's value in a signing key is refused.
   */
  public function testWrongMaterialIsRefused(): void {
    $this->createKey('mixed', KeyPurpose::Signing, KeyStore::PRIMARY, 'env', KeyPurpose::Encryption);
    $this->expectException(\RuntimeException::class);
    $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing);
  }

  /**
   * Two primary keys of one type are refused.
   */
  public function testTwoPrimariesAreRefused(): void {
    $this->createKey('one', KeyPurpose::Signing);
    $this->createKey('two', KeyPurpose::Signing);
    $this->expectException(\RuntimeException::class);
    $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing);
  }

  /**
   * Content Relay's settings hold no keys, and key config holds no secret.
   */
  public function testNoSecretInConfig(): void {
    $this->createKey('signing', KeyPurpose::Signing);
    $export = json_encode([
      $this->config('content_relay.settings')->getRawData(),
      $this->config('key.key.signing')->getRawData(),
    ]);
    $this->assertStringNotContainsString(KeyPurpose::Signing->materialPrefix(), $export);
    $this->assertArrayNotHasKey('signing_keys', $this->config('content_relay.settings')->getRawData());
    $this->assertSame(['status' => KeyStore::PRIMARY], $this->config('key.key.signing')->get('key_type_settings'));
  }

  /**
   * The key types generate and validate their own values.
   */
  public function testKeyTypePlugins(): void {
    $manager = $this->container->get('plugin.manager.key.key_type');
    foreach (KeyPurpose::cases() as $purpose) {
      $type = $manager->createInstance($purpose->keyTypeId());
      $value = $type::generateKeyValue([]);
      $this->assertSame($purpose->materialPrefix(), substr($value, 0, strlen($purpose->materialPrefix())));

      // getErrors(), not hasAnyErrors(): the latter is static, shared by
      // every form state in the request.
      $form_state = new FormState();
      $type->validateKeyValue([], $form_state, $value);
      $this->assertSame([], $form_state->getErrors());
      $invalid = new FormState();
      $type->validateKeyValue([], $invalid, 'not a key');
      $this->assertNotEmpty($invalid->getErrors());
    }
  }

  /**
   * The key form refuses config storage and a second primary key.
   */
  public function testKeyFormValidation(): void {
    $this->createKey('existing', KeyPurpose::Signing);
    $type = $this->container->get('plugin.manager.key.key_type')->createInstance('content_relay_signing');
    $form = [];

    $form_state = (new FormState())->setValues(['id' => 'new', 'key_provider' => 'env', 'status' => KeyStore::ACTIVE]);
    $type->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $form_state = (new FormState())->setValues(['id' => 'new', 'key_provider' => 'config', 'status' => KeyStore::ACTIVE]);
    $type->validateConfigurationForm($form, $form_state);
    $this->assertStringContainsString('"config" provider', (string) $form_state->getError(['#parents' => ['status']]));

    $form_state = (new FormState())->setValues(['id' => 'new', 'key_provider' => 'env', 'status' => KeyStore::PRIMARY]);
    $type->validateConfigurationForm($form, $form_state);
    $this->assertStringContainsString('already the primary key', (string) $form_state->getError(['#parents' => ['status']]));

    // Saving the existing primary again is not a conflict with itself.
    $form_state = (new FormState())->setValues([
      'id' => 'existing',
      'key_provider' => 'env',
      'status' => KeyStore::PRIMARY,
    ]);
    $type->validateConfigurationForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * A message signed here verifies at its recipient, once, through services.
   */
  public function testSignAndVerifyThroughServices(): void {
    $pair = $this->createKey('signing', KeyPurpose::Signing);
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
    $this->assertSame(1, $this->container->get('content_relay.replay_guard')->prune(\PHP_INT_MAX));
  }

}
