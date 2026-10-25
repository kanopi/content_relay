<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Crypto\FileCipher;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\key\Entity\Key;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests receiving files: entities at once, binaries by queue.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class FileSyncTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file'];

  /**
   * The service under test.
   */
  private FileSync $files;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_file', 'type' => 'file'])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_name' => 'field_file',
      'settings' => ['file_extensions' => 'pdf png txt'],
    ])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body'])->save();

    [, $material] = KeyPair::generate(KeyPurpose::Signing);
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY=' . $material);
    Key::create([
      'id' => 'replica_signing',
      'label' => 'Replica signing',
      'key_type' => 'content_relay_signing',
      'key_type_settings' => ['status' => 'primary'],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'CONTENT_RELAY_REPLICA_TEST_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => TRUE,
      ],
      'key_input' => 'none',
    ])->save();
    // File requests go to the fake origin, which signs its answers.
    $origin_key = FakeOrigin::install($this->container->get('state'), 'UNUSED', []);
    $this->updateOrigin(fn (array &$config) => $config['replica'] = $this->container->get('content_relay.site_identity')->getSiteId());
    $this->container->get('content_relay_replica.origin')->set([
      'site_id' => FakeOrigin::SITE_ID,
      'label' => 'Fake origin',
      'url' => 'https://' . FakeOrigin::HOST,
      'signing_keys' => [
        $origin_key->id() => ['public_key' => base64_encode($origin_key->publicKey), 'status' => 'primary'],
      ],
    ]);
    $this->files = $this->container->get('content_relay_replica.file_sync');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_REPLICA_TEST_KEY');
    parent::tearDown();
  }

  /**
   * A file that exists only on the origin, served by the fake origin.
   *
   * @return array{0: array, 1: array}
   *   Its snapshot and its event metadata.
   */
  private function originFile(string $name, string $content): array {
    $uri = 'public://' . $name;
    file_put_contents($uri, $content);
    $file = File::create(['uri' => $uri, 'filename' => $name, 'status' => 1, 'uid' => 0]);
    $file->save();
    $snapshot = json_decode(json_encode($this->container->get('content_relay.entity_serializer')->normalize($file)), TRUE);
    $file->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    $this->container->get('content_relay.file_checksums')->forget($uri);
    $this->serve($snapshot['uuid'], $content, $uri);
    return [$snapshot, FakeOrigin::fileMeta($snapshot['uuid'], $content, $uri)];
  }

  /**
   * Makes the fake origin serve a file.
   */
  private function serve(string $uuid, string $content, string $uri): void {
    $this->updateOrigin(function (array &$config) use ($uuid, $content, $uri): void {
      $config['files'][$uuid] = ['content' => $content, 'uri' => $uri];
    });
  }

  /**
   * Changes the fake origin's state.
   */
  private function updateOrigin(callable $change): void {
    $state = $this->container->get('state');
    $config = $state->get(FakeOrigin::STATE);
    $change($config);
    $state->set(FakeOrigin::STATE, $config);
  }

  /**
   * How many downloads the fake origin answered.
   */
  private function downloads(): int {
    return (int) ($this->container->get('state')->get(FakeOrigin::STATE)['downloads'] ?? 0);
  }

  /**
   * Applies a file's event.
   */
  private function applyFile(array $snapshot, array $meta, int $revision = 1, Operation $operation = Operation::Create): ApplyResult {
    return $this->apply($this->event($snapshot, $operation, $revision, ['revisionable' => FALSE, 'files' => [$meta]]));
  }

  /**
   * Runs the download queue.
   */
  private function runQueue(): void {
    $queue = $this->container->get('queue')->get(FileSync::QUEUE);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(FileSync::QUEUE);
    while (is_object($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }

  /**
   * The sync status of an item.
   */
  private function syncStatus(string $entity_type_id, string $uuid): ?string {
    return $this->container->get('content_relay_replica.sync_state')->get($entity_type_id, $uuid)['status'] ?? NULL;
  }

  /**
   * An origin article using a file, by field and by link in its body.
   */
  private function articleUsing(array $file_snapshot, array $file_meta, string $content): array {
    // Recreate the origin's file briefly, so the article normalizes as the
    // origin would.
    file_put_contents($file_meta['uri'], $content);
    $file = File::create([
      'uuid' => $file_snapshot['uuid'],
      'uri' => $file_meta['uri'],
      'filename' => 'x',
      'status' => 1,
    ]);
    $file->save();
    $path = '/' . trim(PublicStream::basePath(), '/') . '/' . explode('://', $file_meta['uri'])[1];
    $snapshot = $this->originArticle('Uses a file', [
      'field_file' => ['target_id' => $file->id(), 'display' => 1],
      'body' => ['value' => '<a href="' . $path . '">Download</a>', 'format' => 'plain_text'],
    ]);
    $file->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();
    $this->container->get('content_relay.file_checksums')->forget($file_meta['uri']);
    return $snapshot;
  }

  /**
   * The entity is there at once; the binary follows from the queue.
   */
  public function testFileArrives(): void {
    [$snapshot, $meta] = $this->originFile('report.pdf', 'pdf bytes');
    $this->assertSame(ApplyResult::APPLIED, $this->applyFile($snapshot, $meta)->result);
    $file = $this->container->get('entity.repository')->loadEntityByUuid('file', $snapshot['uuid']);
    $this->assertNotNull($file);
    $this->assertFileDoesNotExist('public://report.pdf');
    $this->assertSame(SyncStatus::WAITING_FILES, $this->syncStatus('file', $snapshot['uuid']));
    $this->assertSame(FileSync::PENDING, $this->files->get($snapshot['uuid'])['status']);

    $this->runQueue();
    $this->assertSame('pdf bytes', file_get_contents('public://report.pdf'));
    $this->assertSame(FileSync::DONE, $this->files->get($snapshot['uuid'])['status']);
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus('file', $snapshot['uuid']));

    // The same file again: not downloaded again.
    $downloads = $this->downloads();
    $this->assertSame(ApplyResult::APPLIED, $this->applyFile($snapshot, FakeOrigin::fileMeta($snapshot['uuid'], 'pdf bytes', 'public://report.pdf'), 2, Operation::Update)->result);
    $this->runQueue();
    $this->assertSame($downloads, $this->downloads());
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus('file', $snapshot['uuid']));
  }

  /**
   * Content waits for its files, held unpublished when configured.
   */
  public function testContentWaitsForFiles(): void {
    $this->config('content_relay_replica.settings')->set('files.pending', 'hold')->save();
    [$file_snapshot, $meta] = $this->originFile('photo.png', 'png bytes');
    $article = $this->articleUsing($file_snapshot, $meta, 'png bytes');
    $this->applyFile($file_snapshot, $meta);
    $result = $this->apply($this->event($article, Operation::Create, 1, [
      'dependencies' => [['entity_type' => 'file', 'uuid' => $file_snapshot['uuid']]],
    ]));
    $this->assertSame(ApplyResult::APPLIED, $result->result, (string) $result->message);
    $this->assertSame(SyncStatus::WAITING_FILES, $this->syncStatus('node', $article['uuid']));
    $this->assertFalse($this->local($article['uuid'])->isPublished());

    $this->runQueue();
    $node = $this->local($article['uuid']);
    $this->assertTrue($node->isPublished());
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus('node', $article['uuid']));
    // The link points at this site's copy, and still hashes as the origin's.
    $this->assertStringContainsString('href="/' . trim(PublicStream::basePath(), '/') . '/photo.png"', (string) $node->get('body')->value);
  }

  /**
   * With links left at the origin, text points there and still verifies.
   */
  public function testLinksLeftAtOrigin(): void {
    $this->config('content_relay_replica.settings')->set('files.links', 'origin')->save();
    [$file_snapshot, $meta] = $this->originFile('doc.pdf', 'doc bytes');
    $article = $this->articleUsing($file_snapshot, $meta, 'doc bytes');
    $this->applyFile($file_snapshot, $meta);
    $this->apply($this->event($article, Operation::Create, 1, [
      'dependencies' => [['entity_type' => 'file', 'uuid' => $file_snapshot['uuid']]],
    ]));
    $this->runQueue();
    $node = $this->local($article['uuid']);
    $this->assertStringContainsString('href="https://origin.example.com/sites/default/files/doc.pdf"', (string) $node->get('body')->value);
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus('node', $article['uuid']));
  }

  /**
   * Corrupt downloads are retried, then given up on.
   */
  public function testChecksumMismatch(): void {
    $this->config('content_relay_replica.settings')->set('files.max_attempts', 2)->save();
    [$snapshot, $meta] = $this->originFile('broken.bin', 'good bytes');
    $this->updateOrigin(fn (array &$config) => $config['corrupt_downloads'] = 5);
    $this->applyFile($snapshot, $meta);
    $this->runQueue();
    $record = $this->files->get($snapshot['uuid']);
    $this->assertSame(FileSync::PENDING, $record['status']);
    $this->assertSame('1', (string) $record['attempts']);
    $this->assertStringContainsString('does not match the origin checksum', $record['last_error']);
    $this->assertFileDoesNotExist('public://broken.bin');

    $this->files->process($snapshot['uuid']);
    $this->assertSame(FileSync::ERROR, $this->files->get($snapshot['uuid'])['status']);
    $this->assertSame(SyncStatus::WAITING_FILES, $this->syncStatus('file', $snapshot['uuid']));

    // Pulling again, with the origin healthy, fixes it.
    $this->updateOrigin(fn (array &$config) => $config['corrupt_downloads'] = 0);
    $this->assertSame(FileSync::DONE, $this->files->pull($snapshot['uuid']));
    $this->assertSame(SyncStatus::SYNCED, $this->syncStatus('file', $snapshot['uuid']));
  }

  /**
   * An expired link is renewed through the file endpoint.
   */
  public function testExpiredLinkIsRenewed(): void {
    [$snapshot, $meta] = $this->originFile('late.pdf', 'late bytes');
    $this->updateOrigin(fn (array &$config) => $config['expire_downloads'] = 1);
    $this->applyFile($snapshot, $meta);
    $this->runQueue();
    $this->assertSame(FileSync::DONE, $this->files->get($snapshot['uuid'])['status']);
    $this->assertSame(2, $this->downloads());
    $requests = array_column($this->container->get('state')->get(FakeOrigin::STATE)['requests'], 'path');
    $this->assertContains('/content-relay/v1/files/' . $snapshot['uuid'], $requests);

    // A link already past its expiry is renewed before trying it.
    [$snapshot, $meta] = $this->originFile('old.pdf', 'old bytes');
    $meta['expires'] = time() - 10;
    $this->applyFile($snapshot, $meta);
    $this->runQueue();
    $this->assertSame(FileSync::DONE, $this->files->get($snapshot['uuid'])['status']);
  }

  /**
   * Missing or altered files are found and fetched again.
   */
  public function testVerifyAndFetchOnMiss(): void {
    [$snapshot, $meta] = $this->originFile('kept.pdf', 'kept bytes');
    $this->applyFile($snapshot, $meta);
    $this->runQueue();
    $this->assertSame([], $this->files->verifyAll());

    file_put_contents('public://kept.pdf', 'tampered');
    touch('public://kept.pdf', time() + 5);
    $this->assertSame([$snapshot['uuid']], $this->files->verifyAll());
    $this->runQueue();
    $this->assertSame('kept bytes', file_get_contents('public://kept.pdf'));

    // Requested while missing: redirected to the origin's copy and queued.
    // The web server or Drupal answered 404; the subscriber turns it round.
    unlink('public://kept.pdf');
    $path = 'http://localhost/' . trim(PublicStream::basePath(), '/') . '/kept.pdf';
    $response = $this->missed($path);
    $this->assertSame(307, $response->getStatusCode());
    $this->assertSame('https://origin.example.com/sites/default/files/kept.pdf', $response->headers->get('Location'));
    $this->runQueue();
    $this->assertSame('kept bytes', file_get_contents('public://kept.pdf'));

    // Placeholder mode, for something that is not an image.
    $this->config('content_relay_replica.settings')->set('files.fetch_on_miss', 'placeholder')->save();
    unlink('public://kept.pdf');
    $response = $this->missed($path);
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame('30', $response->headers->get('Retry-After'));

    // Off: the 404 stands. And files that are not synced are left alone.
    $this->config('content_relay_replica.settings')->set('files.fetch_on_miss', 'off')->save();
    $this->assertSame(404, $this->missed($path)->getStatusCode());
    $this->config('content_relay_replica.settings')->set('files.fetch_on_miss', 'redirect')->save();
    $this->assertSame(404, $this->missed('http://localhost/' . trim(PublicStream::basePath(), '/') . '/local-only.pdf')->getStatusCode());
  }

  /**
   * Runs a 404 for a path through the fetch-on-miss subscriber.
   */
  private function missed(string $url): Response {
    $event = new ResponseEvent($this->container->get('http_kernel'), Request::create($url), HttpKernelInterface::MAIN_REQUEST, new Response('Not found', 404));
    $this->container->get('content_relay_replica.fetch_on_miss')->onResponse($event);
    return $event->getResponse();
  }

  /**
   * An encrypted download is decrypted with the key from the event.
   */
  public function testEncryptedDownload(): void {
    [$snapshot, $meta] = $this->originFile('sealed.pdf', 'sealed bytes');
    $key = base64_encode(FileCipher::generateKey());
    $this->updateOrigin(function (array &$config) use ($snapshot, $key): void {
      $config['files'][$snapshot['uuid']]['key'] = $key;
    });
    $meta = FakeOrigin::fileMeta($snapshot['uuid'], 'sealed bytes', 'public://sealed.pdf', 0, $key);
    $this->applyFile($snapshot, $meta);
    $this->assertSame($key, $this->files->get($snapshot['uuid'])['file_key']);
    $this->runQueue();
    $this->assertSame('sealed bytes', file_get_contents('public://sealed.pdf'));
    // The key is not kept once the file is in place.
    $this->assertNull($this->files->get($snapshot['uuid'])['file_key']);

    // With the wrong key, the download is refused rather than stored.
    [$snapshot, $meta] = $this->originFile('wrong.pdf', 'other bytes');
    $this->updateOrigin(function (array &$config) use ($snapshot): void {
      $config['files'][$snapshot['uuid']]['key'] = base64_encode(FileCipher::generateKey());
    });
    $this->applyFile($snapshot, FakeOrigin::fileMeta($snapshot['uuid'], 'other bytes', 'public://wrong.pdf', 0, base64_encode(FileCipher::generateKey())));
    $this->runQueue();
    $this->assertFileDoesNotExist('public://wrong.pdf');
    $this->assertStringContainsString('altered or the key is wrong', $this->files->get($snapshot['uuid'])['last_error']);
  }

  /**
   * Pulling the missing files of an item queues just those.
   */
  public function testPullMissingFiles(): void {
    [$file_snapshot, $meta] = $this->originFile('a.pdf', 'a bytes');
    $article = $this->articleUsing($file_snapshot, $meta, 'a bytes');
    $this->applyFile($file_snapshot, $meta);
    $this->apply($this->event($article, Operation::Create, 1, [
      'dependencies' => [['entity_type' => 'file', 'uuid' => $file_snapshot['uuid']]],
    ]));
    $this->runQueue();
    $node = $this->local($article['uuid']);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame([], $this->files->queueMissingFor($node));

    unlink('public://a.pdf');
    $this->assertSame([$file_snapshot['uuid']], $this->files->queueMissingFor($node));
    $this->runQueue();
    $this->assertFileExists('public://a.pdf');
  }

}
