<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the schema and item endpoints replicas read from.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class ReadEndpointTest extends OriginTestBase {

  /**
   * A JWT from the fake replica.
   */
  private function token(): string {
    $now = new \DateTimeImmutable('@' . time());
    $builder = Builder::new(new JoseEncoder(), ChainedFormatter::default())
      ->issuedBy($this->replica->siteId)
      ->permittedFor($this->replica->originId)
      ->identifiedBy(bin2hex(random_bytes(8)))
      ->issuedAt($now)
      ->canOnlyBeUsedAfter($now)
      ->expiresAt($now->modify('+60 seconds'))
      ->withClaim('scope', 'sync:read')
      ->withHeader('kid', $this->replica->key->id());
    return $this->replica->key->withSecret(fn (string $secret) => $builder->getToken(new Eddsa(), InMemory::plainText($secret))->toString());
  }

  /**
   * Gets a path as the fake replica.
   */
  private function read(string $path, bool $authenticated = TRUE): Response {
    $request = Request::create($path);
    if ($authenticated) {
      $request->headers->set('Authorization', 'Bearer ' . $this->token());
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * The body of a signed answer, checked to be for the fake replica.
   */
  private function answer(Response $response, string $type): array {
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $message = $this->json($response)['message'];
    $this->assertSame($type, $message['type']);
    $this->assertSame($this->replica->siteId, $message['recipient']);
    return $message['body'];
  }

  /**
   * The schema lists what the replica may subscribe to.
   */
  public function testSchema(): void {
    $this->pairAndAcknowledge();
    $bundles = $this->answer($this->read('/content-relay/v1/schema'), 'schema')['bundles'];
    $this->assertSame(['article', 'page'], array_keys($bundles['node']));
    $this->assertSame('string', $bundles['node']['article']['fields']['title']['type']);
    $this->assertArrayNotHasKey('nid', $bundles['node']['article']['fields']);
    $this->assertSame(401, $this->read('/content-relay/v1/schema', FALSE)->getStatusCode());
  }

  /**
   * Limited to the bundles the replica is allowed.
   */
  public function testSchemaOfAllowedBundles(): void {
    $this->pairAndAcknowledge(['node' => ['article']]);
    $this->assertSame(['article'], array_keys($this->answer($this->read('/content-relay/v1/schema'), 'schema')['bundles']['node']));
  }

  /**
   * An item, as an event under the replica's contract, when it may have it.
   */
  public function testItem(): void {
    $this->pairAndAcknowledge();
    $article = $this->node('article', 'Readable');
    $event = $this->answer($this->read('/content-relay/v1/items/node/' . $article->uuid()), 'item')['event'];
    $this->assertSame($article->uuid(), $event['entity_uuid']);
    $this->assertSame('update', $event['operation']);
    $this->assertSame((int) $article->getRevisionId(), $event['source_revision']);
    $this->assertSame(1, $event['contract_version']);
    $this->assertNotEmpty($event['hash']['entity']);
    // Not part of the stream: nothing recorded in the event log.
    $this->deliver();
    $this->assertCount(1, $this->replica->events);

    $page = $this->node('page', 'Not subscribed');
    $this->assertSame(404, $this->read('/content-relay/v1/items/node/' . $page->uuid())->getStatusCode());
    $this->assertSame(404, $this->read('/content-relay/v1/items/node/9a4a4f0e-3b8e-4e8c-9d2c-2c6f8f4b1a01')->getStatusCode());
    $this->container->get('content_relay_origin.item_sync_control')->markNotSyncable($article);
    $this->assertSame(404, $this->read('/content-relay/v1/items/node/' . $article->uuid())->getStatusCode());
    $this->assertSame(401, $this->read('/content-relay/v1/items/node/' . $page->uuid(), FALSE)->getStatusCode());
  }

}
