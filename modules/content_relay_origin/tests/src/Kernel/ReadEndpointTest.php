<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay_origin\Outbox;
use Drupal\entity_test\Entity\EntityTestStringId;
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
 * Tests the endpoints replicas read from.
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
    $schema = $this->answer($this->read('/content-relay/v1/schema'), 'schema');
    $this->assertArrayHasKey('en', $schema['languages']);
    $bundles = $schema['bundles'];
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

  /**
   * Hashes of a bundle's items, a page at a time in UUID order.
   */
  public function testHashes(): void {
    $this->pairAndAcknowledge();
    $nodes = [];
    foreach (['One', 'Two', 'Three'] as $title) {
      $node = $this->node('article', $title);
      $nodes[$node->uuid()] = $node;
    }
    ksort($nodes);
    $uuids = array_keys($nodes);
    $this->node('page', 'Not subscribed');

    $first = $this->answer($this->read('/content-relay/v1/hashes?entity_type=node&bundle=article&limit=2'), 'hashes');
    $this->assertSame(1, $first['contract_version']);
    $this->assertSame(array_slice($uuids, 0, 2), array_column($first['items'], 'uuid'));
    $this->assertSame($uuids[1], $first['next']);
    $latest = $this->container->get('content_relay_origin.hash_store')->latest('node', $uuids[0]);
    $this->assertSame($latest['hashes']->toArray(), $first['items'][0]['hash']);
    $this->assertSame((int) $nodes[$uuids[0]]->getRevisionId(), $first['items'][0]['revision']);
    $this->assertSame((int) $nodes[$uuids[0]]->getChangedTime(), $first['items'][0]['changed']);

    $this->container->get('content_relay_origin.item_sync_control')->markNotSyncable($nodes[$uuids[2]]);
    $second = $this->answer($this->read('/content-relay/v1/hashes?entity_type=node&bundle=article&limit=2&after=' . $first['next']), 'hashes');
    $this->assertSame([$uuids[2]], array_column($second['items'], 'uuid'));
    $this->assertTrue($second['items'][0]['not_syncable']);
    $this->assertNull($second['next']);

    $this->assertSame(404, $this->read('/content-relay/v1/hashes?entity_type=node&bundle=page')->getStatusCode());
    $this->assertSame(404, $this->read('/content-relay/v1/hashes?entity_type=nope&bundle=page')->getStatusCode());
    $this->assertSame(401, $this->read('/content-relay/v1/hashes?entity_type=node&bundle=article', FALSE)->getStatusCode());
  }

  /**
   * What missed events were about, and where those items stand now.
   */
  public function testStream(): void {
    $this->pairAndAcknowledge();
    $kept = $this->node('article', 'Kept');
    $deleted = $this->node('article', 'Deleted');
    $deleted_uuid = $deleted->uuid();
    $deleted->delete();
    $rows = $this->container->get('database')->select(Outbox::TABLE, 'o')
      ->fields('o', ['sequence', 'entity_uuid', 'operation'])
      ->condition('replica_id', $this->replica->siteId)
      ->orderBy('sequence')
      ->execute()
      ->fetchAll();
    $sequences = array_map(fn ($row) => (int) $row->sequence, $rows);

    $events = $this->answer($this->read('/content-relay/v1/stream?sequences=' . implode(',', $sequences) . ',999'), 'stream')['events'];
    $this->assertSame($sequences, array_column($events, 'sequence'));
    $by_uuid = [];
    foreach ($events as $event) {
      $by_uuid[$event['uuid']][] = $event['operation'] . ':' . $event['current'];
    }
    $this->assertSame(['create:exists'], $by_uuid[$kept->uuid()]);
    $this->assertSame(['create:gone', 'delete:gone'], $by_uuid[$deleted_uuid]);
    $this->assertSame(401, $this->read('/content-relay/v1/stream?sequences=1', FALSE)->getStatusCode());
  }

  /**
   * Content saved before hashes were kept gets them from a backfill.
   */
  public function testBackfill(): void {
    $this->pairAndAcknowledge();
    $node = $this->node('article', 'Old content');
    $store = $this->container->get('content_relay_origin.hash_store');
    $store->forget('node', $node->uuid());
    $this->assertSame([], $store->latestMany('node', [$node->uuid()]));

    $this->assertGreaterThanOrEqual(1, $this->container->get('content_relay_origin.hash_backfill')->run());
    $latest = $store->latest('node', $node->uuid());
    $this->assertNotNull($latest);
    $this->assertSame((int) $node->getRevisionId(), $latest['revision']);
    // Nothing left to do.
    $this->assertSame(0, $this->container->get('content_relay_origin.hash_backfill')->run());
  }

  /**
   * Backfill goes through every published type in chunks of 50.
   */
  public function testBackfillInChunks(): void {
    $this->enableModules(['entity_test']);
    $this->installEntitySchema('entity_test_string_id');
    $this->pairAndAcknowledge();
    $this->config('content_relay_origin.settings')->set('bundles', [
      'node' => ['article'],
      'entity_test_string_id' => ['entity_test_string_id'],
    ])->save();
    $store = $this->container->get('content_relay_origin.hash_store');
    $items = [];
    for ($i = 1; $i <= 120; $i++) {
      $items[] = $this->node('article', "Item $i");
    }
    // String IDs, which sort and page as strings.
    foreach (['alpha', 'beta', 'gamma'] as $id) {
      $entity = EntityTestStringId::create(['id' => $id, 'name' => $id]);
      $entity->save();
      $items[] = $entity;
    }
    foreach ($items as $item) {
      $store->forget($item->getEntityTypeId(), $item->uuid());
    }
    // One already has its hash, and is left alone.
    $store->record($items[60]);

    $progress = [];
    $backfill = $this->container->get('content_relay_origin.hash_backfill');
    $count = $backfill->run(FALSE, function (string $entity_type_id, string $bundle, int $done) use (&$progress): void {
      $progress[] = "$entity_type_id:$done";
    });
    $this->assertSame(122, $count);
    $this->assertSame(['node:50', 'node:99', 'node:119', 'entity_test_string_id:122'], $progress);
    foreach ($items as $item) {
      $this->assertNotNull($store->latest($item->getEntityTypeId(), $item->uuid()), $item->label());
    }
    $this->assertSame(0, $backfill->run());
    $this->assertSame(123, $backfill->run(TRUE));
  }

  /**
   * A replica over its read limit is told to wait.
   */
  public function testReadLimit(): void {
    $this->pairAndAcknowledge();
    foreach (['One', 'Two', 'Three'] as $title) {
      $node = $this->node('article', $title);
    }
    $this->config('content_relay_origin.settings')->set('read_limit', ['items' => 3, 'window' => 60])->save();

    $page = $this->answer($this->read('/content-relay/v1/hashes?entity_type=node&bundle=article&limit=2'), 'hashes');
    $this->assertCount(2, $page['items']);
    // One left: the next page is cut to it.
    $page = $this->answer($this->read('/content-relay/v1/hashes?entity_type=node&bundle=article&limit=2&after=' . $page['next']), 'hashes');
    $this->assertCount(1, $page['items']);

    $response = $this->read('/content-relay/v1/items/node/' . $node->uuid());
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('rate_limited', $this->json($response)['result']);
    $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    $this->assertSame(429, $this->read('/content-relay/v1/stream?sequences=1')->getStatusCode());
  }

  /**
   * The read endpoints only read: other methods are refused.
   */
  public function testReadOnly(): void {
    $this->pairAndAcknowledge();
    $node = $this->node('article', 'Not writable');
    foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
      foreach ([
        '/content-relay/v1/items/node/' . $node->uuid(),
        '/content-relay/v1/hashes',
        '/content-relay/v1/schema',
        '/content-relay/v1/stream',
      ] as $path) {
        $request = Request::create($path, $method, [], [], [], [], json_encode(['title' => 'Changed']));
        $request->headers->set('Authorization', 'Bearer ' . $this->token());
        $this->assertSame(405, $this->container->get('http_kernel')->handle($request)->getStatusCode(), "$method $path");
      }
    }
    $this->assertSame('Not writable', $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id())->label());
  }

}
