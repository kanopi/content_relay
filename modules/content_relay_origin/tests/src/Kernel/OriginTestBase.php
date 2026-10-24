<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_origin\ContractManager;
use Drupal\content_relay_origin\HashStore;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\PairingCodes;
use Drupal\content_relay_origin\ReplicaRegistry;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An origin publishing articles and pages, with a fake replica to talk to.
 */
abstract class OriginTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_relay',
    'content_relay_origin',
    'key',
    'node',
    'system',
    'text',
    'field',
    'filter',
    'user',
  ];

  /**
   * The origin's signing key.
   */
  protected KeyPair $originKey;

  /**
   * The replica at the other end of the HTTP client.
   */
  protected FakeReplica $replica;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Before anything is installed or saved: saving any entity builds the
    // change recorder, and with it everything that holds the HTTP client.
    // The replica learns the origin's site ID once it exists.
    $this->replica = new FakeReplica('');
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($this->replica)]));
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('content_relay', ['content_relay_seen_message']);
    $this->installSchema('content_relay_origin', [
      ReplicaRegistry::TABLE,
      Outbox::TABLE,
      HashStore::TABLE,
      ContractManager::TABLE,
      PairingCodes::TABLE,
    ]);
    $this->installConfig(['content_relay', 'content_relay_origin', 'node', 'filter']);
    content_relay_install(FALSE);
    $this->container->get('router.builder')->rebuild();
    $this->replica->originId = $this->container->get('content_relay.site_identity')->getSiteId();

    [$this->originKey, $material] = KeyPair::generate(KeyPurpose::Signing);
    putenv('CONTENT_RELAY_ORIGIN_TEST_KEY=' . $material);
    Key::create([
      'id' => 'origin_signing',
      'label' => 'Origin signing',
      'key_type' => 'content_relay_signing',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_ORIGIN_TEST_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->config('content_relay_origin.settings')->set('bundles', ['node' => ['article', 'page']])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_ORIGIN_TEST_KEY');
    parent::tearDown();
  }

  /**
   * Posts JSON to a path through the HTTP kernel.
   */
  protected function post(string $path, array $body, string $ip = '127.0.0.1'): Response {
    $request = Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip], json_encode($body));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Decodes a JSON response.
   */
  protected function json(Response $response): array {
    return json_decode((string) $response->getContent(), TRUE) ?? [];
  }

  /**
   * Generates a code and pairs the fake replica with it.
   */
  protected function pair(?array $allowed = NULL, array $bundles = ['node' => ['article']]): array {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate($allowed, 1)['code'];
    $response = $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code, $bundles));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    return $this->json($response);
  }

  /**
   * Pairs and acknowledges contract version 1 through the inbox.
   */
  protected function pairAndAcknowledge(?array $allowed = NULL, array $bundles = ['node' => ['article']]): void {
    $this->pair($allowed, $bundles);
    $response = $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Saves an item.
   */
  protected function node(string $type = 'article', string $title = 'Hello'): NodeInterface {
    $node = Node::create(['type' => $type, 'title' => $title, 'uid' => 0]);
    $node->save();
    return $node;
  }

  /**
   * Delivers to the fake replica.
   */
  protected function deliver(): void {
    $this->container->get('content_relay_origin.delivery')->deliver($this->replica->siteId);
  }

}
