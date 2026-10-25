<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\FileCipher;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay_origin\Crypto\ReplicaPolicy;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\key\Entity\Key;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests encryption, downgrade protection and key rotation on the origin.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class EncryptionTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file'];

  /**
   * The origin's encryption key.
   */
  private KeyPair $originEncryption;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    [$this->originEncryption, $material] = KeyPair::generate(KeyPurpose::Encryption);
    putenv('CONTENT_RELAY_ORIGIN_TEST_ENCRYPTION=' . $material);
    Key::create([
      'id' => 'origin_encryption',
      'label' => 'Origin encryption',
      'key_type' => 'content_relay_encryption',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_ORIGIN_TEST_ENCRYPTION',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_ORIGIN_TEST_ENCRYPTION');
    parent::tearDown();
  }

  /**
   * Pairs asking for encryption, and acknowledges sealed.
   */
  private function pairEncrypted(): array {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $request = $this->replica->pairingRequest($code, ['node' => ['article']], [
      'requested' => ['bundles' => ['node' => ['article']], 'encryption' => 'sealed'] + SubscriptionContract::defaultReplicaTerms(),
    ]);
    $response = $this->json($this->post('/content-relay/v1/pair', $request));
    $this->replica->originEncryptionKey = [
      'id' => $this->originEncryption->id(),
      'public_key' => $this->originEncryption->publicKey,
    ];
    $this->replica->seal = TRUE;
    $ack = $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
    $this->assertSame(200, $ack->getStatusCode(), (string) $ack->getContent());
    return $response;
  }

  /**
   * Gets a path through the HTTP kernel.
   */
  private function get(string $path): Response {
    return $this->container->get('http_kernel')->handle(Request::create($path));
  }

  /**
   * Encrypted contracts and events: unreadable on the way, readable there.
   */
  public function testEncryptedDelivery(): void {
    $pairing = $this->pairEncrypted();
    $this->assertSame('sealed', $pairing['contract']['encryption']);
    $this->assertArrayNotHasKey('message', $pairing['contract']);
    $this->assertSame('sealed', $this->replica->open($pairing['contract'])['body']['effective']['encryption']);

    Node::create(['type' => 'article', 'title' => 'Confidential launch plan', 'uid' => 0])->save();
    $this->deliver();
    $raw = json_encode(end($this->replica->envelopes));
    $this->assertStringNotContainsString('Confidential', $raw);
    $this->assertSame('sealed', end($this->replica->envelopes)['encryption']);
    $snapshot = $this->replica->events[0]['snapshot'];
    $title = $snapshot['shared']['title'] ?? $snapshot['translations'][$snapshot['default_langcode']]['title'];
    $this->assertSame('Confidential launch plan', $title[0]['value']);

    // A validly signed but unencrypted message is refused.
    $this->replica->seal = FALSE;
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', [
      'entity_type' => 'node',
      'uuid' => 'x',
      'bundle' => 'article',
    ]));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('encryption_required', $this->json($response)['result']);
  }

  /**
   * Switching encryption on: both modes accepted for the window, then not.
   */
  public function testTransitionWindow(): void {
    $this->pairAndAcknowledge();
    Node::create(['type' => 'article', 'title' => 'Before', 'uid' => 0])->save();
    $this->deliver();
    $this->assertSame('none', end($this->replica->envelopes)['encryption']);

    // The replica asks for encryption; the contract comes back unencrypted,
    // because the one in force is.
    $terms = ['bundles' => ['node' => ['article']], 'encryption' => 'sealed'] + SubscriptionContract::defaultReplicaTerms();
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('subscription_update', ['replica_terms' => $terms]));
    $contract = $this->json($response)['contract'];
    $this->assertSame('none', $contract['encryption']);
    $this->assertSame('sealed', $contract['message']['body']['effective']['encryption']);
    $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 2]));

    Node::create(['type' => 'article', 'title' => 'After', 'uid' => 0])->save();
    $this->deliver();
    $this->assertSame('sealed', end($this->replica->envelopes)['encryption']);

    // Unencrypted still accepted during the window...
    $notice = ['entity_type' => 'node', 'uuid' => 'a1b2c3', 'bundle' => 'article'];
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice))->getStatusCode());
    // ...and refused after it.
    $this->container->get('state')->set(ReplicaPolicy::STATE_CHANGED, [$this->replica->siteId => time() - 7200]);
    $this->assertSame(403, $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice))->getStatusCode());
    $this->replica->originEncryptionKey = [
      'id' => $this->originEncryption->id(),
      'public_key' => $this->originEncryption->publicKey,
    ];
    $this->replica->seal = TRUE;
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice))->getStatusCode());
  }

  /**
   * This site's require setting forces encryption, and refuses without keys.
   */
  public function testRequireEncryption(): void {
    $this->config('content_relay.settings')->set('encryption.require', TRUE)->save();
    $pairing = $this->pair();
    $this->assertSame('sealed', $this->replica->open($pairing['contract'])['body']['effective']['encryption']);
    $plain_ack = $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
    $this->assertSame(403, $plain_ack->getStatusCode());

    // Without keys on both sides it cannot be granted, and is said so.
    $this->container->get('entity_type.manager')->getStorage('key')->load('origin_encryption')->delete();
    $this->container->get('content_relay.key_store')->reset();
    $contract = $this->container->get('content_relay_origin.contract_manager')->propose($this->replica->siteId, NULL, NULL, 'origin');
    $this->assertSame('none', $contract->encryption());
    $this->assertSame('Both sites need an encryption key.', $contract->notGranted()[0]['reason']);
  }

  /**
   * Files are encrypted on download with a key only the sealed event has.
   */
  public function testEncryptedFiles(): void {
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_file', 'type' => 'file'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_file'])->save();
    $this->pairEncrypted();
    file_put_contents('public://plan.pdf', 'secret pdf bytes');
    $file = File::create(['uri' => 'public://plan.pdf', 'filename' => 'plan.pdf', 'status' => 1]);
    $file->save();
    Node::create([
      'type' => 'article',
      'title' => 'Plan',
      'uid' => 0,
      'field_file' => ['target_id' => $file->id(), 'display' => 1],
    ])->save();
    $this->deliver();

    $meta = $this->replica->events[0]['files'][0];
    $this->assertSame(FileCipher::NAME, $meta['encryption']['cipher']);
    $this->assertStringContainsString('ek=' . $this->originEncryption->id(), $meta['download']);
    $response = $this->get($meta['download']);
    $this->assertSame(200, $response->getStatusCode());
    ob_start();
    $response->sendContent();
    $downloaded = (string) ob_get_clean();
    $this->assertStringNotContainsString('secret pdf', $downloaded);

    $in = fopen('php://memory', 'w+');
    fwrite($in, $downloaded);
    rewind($in);
    $out = fopen('php://memory', 'w+');
    FileCipher::decrypt($in, $out, base64_decode($meta['encryption']['key']));
    rewind($out);
    $this->assertSame('secret pdf bytes', stream_get_contents($out));

    // The encryption key named in the link is signed: it cannot be dropped
    // to get the file in the clear.
    $this->assertSame(403, $this->get(preg_replace('/&ek=[^&]+/', '', $meta['download']))->getStatusCode());
  }

  /**
   * The origin rotates its signing key; replicas follow at their own pace.
   */
  public function testOriginKeyRotation(): void {
    $this->pairAndAcknowledge();
    $old = $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing)->id();
    $rotation = $this->container->get('content_relay.key_rotation');
    $path = $this->siteDirectory . '/next_signing.key';
    $new = $rotation->generate(KeyPurpose::Signing, 'next_signing', $path)->id();

    // The replica misses the announcement: it keeps getting the old key.
    $this->replica->dropAcks = TRUE;
    $results = $rotation->rotate(KeyPurpose::Signing, 'next_signing');
    $this->assertSame([$this->replica->siteId => FALSE], $results);
    $this->assertSame($new, $this->container->get('content_relay.key_store')->getPrimary(KeyPurpose::Signing)->id());
    Node::create(['type' => 'article', 'title' => 'Still old key', 'uid' => 0])->save();
    $this->deliver();
    $this->assertSame($old, end($this->replica->envelopes)['signing_key_id']);

    // Once it confirms a contract listing the new key, the new key is used.
    $this->replica->dropAcks = FALSE;
    $this->assertTrue($this->container->get('content_relay_origin.contract_manager')->push($this->replica->siteId, TRUE));
    $this->assertArrayHasKey($new, end($this->replica->contractTerms)['joint']['origin_keys']['signing']);
    Node::create(['type' => 'article', 'title' => 'New key', 'uid' => 0])->save();
    $this->deliver();
    $this->assertSame($new, end($this->replica->envelopes)['signing_key_id']);

    // Retiring the old key tells the replica to stop accepting it.
    $this->assertSame([$this->replica->siteId => TRUE], $rotation->retire(KeyPurpose::Signing, 'origin_signing'));
    $this->assertSame([$new], array_keys(end($this->replica->contractTerms)['joint']['origin_keys']['signing']));
    $this->assertNull($this->container->get('content_relay.key_store')->get(KeyPurpose::Signing, $old));
  }

  /**
   * A replica rotates: new key announced with the old, old refused once gone.
   */
  public function testReplicaKeyRotation(): void {
    $this->pairAndAcknowledge();
    $old = $this->replica->key;
    [$new] = KeyPair::generate(KeyPurpose::Signing);
    $terms = ['bundles' => ['node' => ['article']]] + SubscriptionContract::defaultReplicaTerms();

    // Announced, signed with the old key.
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('subscription_update', [
      'replica_terms' => $terms,
      'keys' => FakeReplica::keySet([$old, $new], $this->replica->encryption),
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $notice = ['entity_type' => 'node', 'uuid' => 'a1b2c3', 'bundle' => 'article'];
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice, $new))->getStatusCode());
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice, $old))->getStatusCode());

    // The old key retired: no longer listed, no longer accepted.
    $this->post('/content-relay/v1/messages', $this->replica->message('subscription_update', [
      'replica_terms' => $terms,
      'keys' => FakeReplica::keySet([$new], $this->replica->encryption),
    ], $new));
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice, $old));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('unknown_key', $this->json($response)['result']);
    $this->assertSame(200, $this->post('/content-relay/v1/messages', $this->replica->message('detach_notice', $notice, $new))->getStatusCode());

    // A malformed key set is refused and changes nothing.
    $bad = FakeReplica::keySet([$new]);
    $bad['signing'][array_key_first($bad['signing'])]['public_key'] = base64_encode('short');
    $this->assertSame(400, $this->post('/content-relay/v1/messages', $this->replica->message('subscription_update', [
      'replica_terms' => $terms,
      'keys' => $bad,
    ], $new))->getStatusCode());
    $this->assertSame(KeyStore::PRIMARY, $this->container->get('content_relay_origin.replica_registry')->get($this->replica->siteId)->signingKeys[$new->id()]['status']);
  }

}
