<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay_origin\File\FileLinks;
use Drupal\content_relay_origin\Report\ReportIndex;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests sending files: as dependencies, by signed link and by endpoint.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class FileTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['file'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_file', 'type' => 'file'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_file'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'body', 'type' => 'text_long'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'body'])->save();
    $this->pairAndAcknowledge();
  }

  /**
   * Stores a file and its entity.
   */
  private function file(string $name, string $content): FileInterface {
    $directory = 'public://';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents('public://' . $name, $content);
    $file = File::create(['uri' => 'public://' . $name, 'filename' => $name, 'status' => 1]);
    $file->save();
    return $file;
  }

  /**
   * An article using a file in its file field.
   */
  private function articleWith(FileInterface $file, string $title = 'With file'): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'uid' => 0,
      'field_file' => ['target_id' => $file->id(), 'display' => 1],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Gets a path through the HTTP kernel.
   */
  private function get(string $path, array $headers = []): Response {
    $request = Request::create($path, 'GET');
    foreach ($headers as $name => $value) {
      $request->headers->set($name, $value);
    }
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * The bytes a response sends.
   */
  private function body(Response $response): string {
    if ($response instanceof BinaryFileResponse) {
      return (string) file_get_contents($response->getFile()->getPathname());
    }
    return (string) $response->getContent();
  }

  /**
   * A JWT from the fake replica.
   */
  private function token(string $scope = 'sync:read'): string {
    $now = new \DateTimeImmutable('@' . time());
    $builder = Builder::new(new JoseEncoder(), ChainedFormatter::default())
      ->issuedBy($this->replica->siteId)
      ->permittedFor($this->replica->originId)
      ->identifiedBy(bin2hex(random_bytes(8)))
      ->issuedAt($now)
      ->canOnlyBeUsedAfter($now)
      ->expiresAt($now->modify('+60 seconds'))
      ->withClaim('scope', $scope)
      ->withHeader('kid', $this->replica->key->id());
    return $this->replica->key->withSecret(fn (string $secret) => $builder->getToken(new Eddsa(), InMemory::plainText($secret))->toString());
  }

  /**
   * A file goes before the item using it, with what the replica needs.
   */
  public function testFileFollowsContent(): void {
    $file = $this->file('report.pdf', 'pdf bytes');
    $this->articleWith($file);
    $this->deliver();

    $this->assertSame(['file', 'node'], array_column($this->replica->events, 'entity_type'));
    $meta = $this->replica->events[0]['files'][0];
    $this->assertSame($file->uuid(), $meta['uuid']);
    $this->assertSame(hash('sha256', 'pdf bytes'), $meta['sha256']);
    $this->assertSame(9, $meta['size']);
    $this->assertStringStartsWith('/content-relay/v1/files/' . $file->uuid() . '/download?', $meta['download']);
    $this->assertStringEndsWith('/report.pdf', $meta['url']);
    $this->assertSame([['entity_type' => 'file', 'uuid' => $file->uuid()]], $this->replica->events[1]['dependencies']);

    // A new version of the file goes to the replica that has it.
    file_put_contents($file->getFileUri(), 'pdf bytes, revised');
    touch($file->getFileUri(), time() + 5);
    $file->save();
    $this->deliver();
    $this->assertSame('update', $this->replica->events[2]['operation']);
    $this->assertSame(hash('sha256', 'pdf bytes, revised'), $this->replica->events[2]['files'][0]['sha256']);

    // Files nobody sent are not.
    $this->file('unused.txt', 'unused');
    $this->deliver();
    $this->assertCount(3, $this->replica->events);
  }

  /**
   * Signed links serve the file; altered or expired ones do not.
   */
  public function testDownloadLinks(): void {
    $file = $this->file('photo.jpg', 'jpeg bytes');
    $this->articleWith($file);
    $this->deliver();
    $download = $this->replica->events[0]['files'][0]['download'];

    $response = $this->get($download);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('jpeg bytes', $this->body($response));
    $this->assertSame(hash('sha256', 'jpeg bytes'), $response->headers->get('X-Content-Relay-Sha256'));

    $this->assertSame(403, $this->get(str_replace('replica=', 'replica=x', $download))->getStatusCode());
    $this->assertSame(403, $this->get(preg_replace('/signature=[^&]+/', 'signature=AAAA', $download))->getStatusCode());
    $this->assertSame(403, $this->get(preg_replace('/expires=(\d+)/', 'expires=9$1', $download))->getStatusCode());

    // A link signed correctly but in the past.
    $past = $this->createMock(TimeInterface::class);
    $past->method('getCurrentTime')->willReturn(time() - 7200);
    $links = new FileLinks($this->container->get('content_relay.key_store'), $this->container->get('config.factory'), $past);
    $expired = $links->build($file, $this->replica->siteId)['download'];
    $response = $this->get($expired);
    $this->assertSame(410, $response->getStatusCode());
    $this->assertSame('expired', $this->json($response)['result']);
  }

  /**
   * The file endpoint gives fresh links, only for files the replica may have.
   */
  public function testFileEndpoint(): void {
    $file = $this->file('shared.pdf', 'shared');
    $this->articleWith($file);
    $response = $this->get('/content-relay/v1/files/' . $file->uuid(), ['Authorization' => 'Bearer ' . $this->token()]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    // A signed message from the origin to this replica.
    $answer = $this->json($response);
    $this->assertSame('file_info', $answer['message']['type']);
    $this->assertSame($this->replica->siteId, $answer['message']['recipient']);
    $info = $answer['message']['body'];
    $this->assertSame(hash('sha256', 'shared'), $info['sha256']);
    $this->assertSame(200, $this->get($info['download'])->getStatusCode());

    // No token, a token for another scope, a reused token.
    $this->assertSame(401, $this->get('/content-relay/v1/files/' . $file->uuid())->getStatusCode());
    $this->assertSame(401, $this->get('/content-relay/v1/files/' . $file->uuid(), ['Authorization' => 'Bearer ' . $this->token('sync:write')])->getStatusCode());
    $token = $this->token();
    $this->get('/content-relay/v1/files/' . $file->uuid(), ['Authorization' => 'Bearer ' . $token]);
    $this->assertSame(401, $this->get('/content-relay/v1/files/' . $file->uuid(), ['Authorization' => 'Bearer ' . $token])->getStatusCode());

    // Only used by a bundle outside the contract.
    $page_file = $this->file('page.pdf', 'page');
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'field_file'])->save();
    Node::create(['type' => 'page', 'title' => 'Page', 'uid' => 0, 'field_file' => ['target_id' => $page_file->id()]])->save();
    $this->assertSame(404, $this->get('/content-relay/v1/files/' . $page_file->uuid(), ['Authorization' => 'Bearer ' . $this->token()])->getStatusCode());

    // Only used by an item not syncable for this replica.
    $secret = $this->file('secret.pdf', 'secret');
    $node = $this->articleWith($secret, 'Secret');
    $this->container->get('content_relay_origin.item_sync_control')->markNotSyncable($node);
    $this->assertSame(404, $this->get('/content-relay/v1/files/' . $secret->uuid(), ['Authorization' => 'Bearer ' . $this->token()])->getStatusCode());
    $link = $this->container->get('content_relay_origin.file_links')->build($secret, $this->replica->siteId)['download'];
    $this->assertSame(404, $this->get($link)->getStatusCode());

    // Unused.
    $unused = $this->file('unused.pdf', 'unused');
    $this->assertSame(404, $this->get('/content-relay/v1/files/' . $unused->uuid(), ['Authorization' => 'Bearer ' . $this->token()])->getStatusCode());
  }

  /**
   * A link to a file the origin does not have is reported and left alone.
   */
  public function testUnresolvedLink(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Broken link',
      'uid' => 0,
      'body' => ['value' => '<a href="/' . $this->publicPath() . '/gone.pdf">Gone</a>', 'format' => 'plain_text'],
    ]);
    $node->save();
    $this->deliver();
    $event = $this->replica->events[0];
    $this->assertSame([['field' => 'body', 'entity_type' => 'file', 'id' => '/' . $this->publicPath() . '/gone.pdf']], $event['unresolved']);
    $body = $event['snapshot']['shared']['body'] ?? $event['snapshot']['translations'][$event['default_langcode']]['body'];
    $this->assertStringContainsString('gone.pdf">Gone', $body[0]['value']);
    $unresolved = $this->container->get('database')->select(ReportIndex::TABLE, 'r')->fields('r', ['unresolved_files'])->condition('uuid', $node->uuid())->execute()->fetchField();
    $this->assertSame('1', (string) $unresolved);
  }

  /**
   * The public files path, without slashes.
   */
  private function publicPath(): string {
    return trim(PublicStream::basePath(), '/');
  }

}
