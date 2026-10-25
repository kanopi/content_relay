<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay_replica\Crypto\OriginPolicy;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\key\Entity\Key;
use Drupal\key\KeyInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests encryption, downgrade protection and key rotation on a replica.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class EncryptionTest extends ReplicaTestBase {

  /**
   * This replica's encryption key.
   */
  private KeyPair $encryption;

  /**
   * The origin's encryption key.
   */
  private KeyPair $originEncryption;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
    foreach (['signing' => KeyPurpose::Signing, 'encryption' => KeyPurpose::Encryption] as $name => $purpose) {
      [$pair, $material] = KeyPair::generate($purpose);
      putenv('CONTENT_RELAY_REPLICA_TEST_' . strtoupper($name) . '=' . $material);
      Key::create([
        'id' => 'replica_' . $name,
        'label' => 'Replica ' . $name,
        'key_type' => $purpose->keyTypeId(),
        'key_type_settings' => ['status' => 'primary'],
        'key_provider' => 'env',
        'key_provider_settings' => [
          'env_variable' => 'CONTENT_RELAY_REPLICA_TEST_' . strtoupper($name),
          'base64_encoded' => FALSE,
          'strip_line_breaks' => TRUE,
        ],
        'key_input' => 'none',
      ])->save();
      if ($purpose === KeyPurpose::Encryption) {
        $this->encryption = $pair;
      }
    }
    [$this->originEncryption] = KeyPair::generate(KeyPurpose::Encryption);
    $origin = $this->container->get('content_relay_replica.origin');
    $peer = $origin->get();
    $origin->setKeys($peer['signing_keys'], [
      $this->originEncryption->id() => [
        'public_key' => base64_encode($this->originEncryption->publicKey),
        'status' => 'primary',
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_SIGNING');
    putenv('CONTENT_RELAY_REPLICA_TEST_ENCRYPTION');
    parent::tearDown();
  }

  /**
   * A message from the origin, sealed to this replica or not.
   */
  private function fromOrigin(string $type, array $body, bool $sealed, ?int $sequence = NULL): array {
    $mode = $sealed ? EncryptionMode::Sealed : EncryptionMode::None;
    $message = new Message((new PhpUuid())->generate(), $type, self::ORIGIN, $this->container->get('content_relay.site_identity')->getSiteId(), time(), $mode, $body, $sequence);
    $envelope = $this->container->get('content_relay.message_signer')->signMessage($message, $this->originKey);
    if ($sealed) {
      return MessageSigner::seal($envelope, $this->encryption->id(), $this->encryption->publicKey)->toArray();
    }
    return $envelope->toArray();
  }

  /**
   * Posts to the endpoint.
   */
  private function post(array $body): Response {
    $request = Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * An event as a message body.
   */
  private function eventMessage(string $title, bool $sealed): array {
    $event = $this->event($this->originArticle($title), Operation::Create, 1);
    return $this->fromOrigin('event', $event->toArray(), $sealed, $event->sequence);
  }

  /**
   * An encrypted contract: sealed events applied, unencrypted ones refused.
   */
  public function testEncryptedContract(): void {
    $this->container->get('content_relay_replica.contracts')->save($this->sealedContract(2));
    $response = $this->post($this->eventMessage('Sealed', TRUE));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $response = $this->post($this->eventMessage('Plain', FALSE));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('encryption_required', json_decode((string) $response->getContent(), TRUE)['result']);

    // What this site sends the origin is sealed to the origin's key.
    $envelope = $this->container->get('content_relay.message_signer')->sign(self::ORIGIN, 'detach_notice', ['x' => 1]);
    $this->assertSame(EncryptionMode::Sealed, $envelope->encryption);
    $this->assertSame($this->originEncryption->id(), $envelope->encryptionKeyId);
    $this->assertNotNull($this->originEncryption->open(base64_decode((string) $envelope->ciphertext)));
  }

  /**
   * A pushed contract switching encryption on opens the transition window.
   */
  public function testTransitionWindow(): void {
    $response = $this->post($this->fromOrigin('contract', $this->sealedContract(2)->toArray(), FALSE));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('sealed', $this->container->get('content_relay_replica.contracts')->getCurrent()->encryption());
    // The acknowledgement in the answer is already sealed.
    $this->assertSame('sealed', json_decode((string) $response->getContent(), TRUE)['ack']['encryption']);

    $this->assertSame(200, $this->post($this->eventMessage('In flight', FALSE))->getStatusCode());
    $this->container->get('state')->set(OriginPolicy::STATE_CHANGED, time() - 7200);
    $this->assertSame(403, $this->post($this->eventMessage('Too late', FALSE))->getStatusCode());
    $this->assertSame(200, $this->post($this->eventMessage('Sealed', TRUE))->getStatusCode());
  }

  /**
   * This site's own setting refuses unencrypted messages whatever the terms.
   */
  public function testRequireEncryption(): void {
    $this->config('content_relay.settings')->set('encryption.require', TRUE)->save();
    $this->assertSame(403, $this->post($this->eventMessage('Plain', FALSE))->getStatusCode());
    $this->assertSame(200, $this->post($this->eventMessage('Sealed', TRUE))->getStatusCode());
  }

  /**
   * Rotating this replica's signing key goes through the origin first.
   */
  public function testKeyRotation(): void {
    $origin_key = FakeOrigin::install($this->container->get('state'), 'UNUSED', ['node' => ['article']]);
    $state = $this->container->get('state');
    $config = $state->get(FakeOrigin::STATE);
    $config['replica'] = $this->container->get('content_relay.site_identity')->getSiteId();
    $config['versions'] = [1];
    $state->set(FakeOrigin::STATE, $config);
    $this->container->get('content_relay_replica.origin')->set([
      'site_id' => FakeOrigin::SITE_ID,
      'url' => 'https://' . FakeOrigin::HOST,
      'signing_keys' => [
        $origin_key->id() => ['public_key' => base64_encode($origin_key->publicKey), 'status' => 'primary'],
      ],
    ]);
    $key_store = $this->container->get('content_relay.key_store');
    $old = $key_store->getPrimary(KeyPurpose::Signing)->id();
    $rotation = $this->container->get('content_relay.key_rotation');
    $new = $rotation->generate(KeyPurpose::Signing, 'replica_signing_next', $this->siteDirectory . '/next.key')->id();
    $generated = $this->container->get('entity_type.manager')->getStorage('key')->load('replica_signing_next');
    $this->assertInstanceOf(KeyInterface::class, $generated);
    $this->assertSame(KeyStore::ACTIVE, KeyStore::statusOf($generated));

    // The origin cannot be told: nothing changes.
    $config['down'] = TRUE;
    $state->set(FakeOrigin::STATE, $config);
    try {
      $rotation->rotate(KeyPurpose::Signing, 'replica_signing_next');
      $this->fail('Rotated without telling the origin.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('nothing was changed', $e->getMessage());
    }
    $this->assertSame($old, $key_store->getPrimary(KeyPurpose::Signing)->id());

    // Told, signed with the old key, then switched.
    $config = $state->get(FakeOrigin::STATE);
    $config['down'] = FALSE;
    $state->set(FakeOrigin::STATE, $config);
    $this->assertSame([FakeOrigin::SITE_ID => TRUE], $rotation->rotate(KeyPurpose::Signing, 'replica_signing_next'));
    $this->assertSame($new, $key_store->getPrimary(KeyPurpose::Signing)->id());
    $updates = array_values(array_filter($state->get(FakeOrigin::STATE)['requests'], fn ($r) => ($r['body']['message']['type'] ?? NULL) === 'subscription_update'));
    $first = $updates[count($updates) - 2]['body'];
    $this->assertSame($old, $first['signing_key_id']);
    $this->assertSame([$old, $new], array_keys($first['message']['body']['keys']['signing']));
    $this->assertSame($new, end($updates)['body']['signing_key_id']);

    // Retired: no longer announced.
    $rotation->retire(KeyPurpose::Signing, 'replica_signing');
    $updates = array_values(array_filter($state->get(FakeOrigin::STATE)['requests'], fn ($r) => ($r['body']['message']['type'] ?? NULL) === 'subscription_update'));
    $this->assertSame([$new], array_keys(end($updates)['body']['message']['body']['keys']['signing']));
    $this->assertNull($key_store->get(KeyPurpose::Signing, $old));
  }

  /**
   * A contract like the test base's, with encryption on.
   */
  private function sealedContract(int $version): SubscriptionContract {
    $terms = $this->contract($version, ['node' => ['article'], 'taxonomy_term' => ['tags']])->toArray();
    $terms['effective']['encryption'] = 'sealed';
    return SubscriptionContract::fromArray($terms);
  }

}
