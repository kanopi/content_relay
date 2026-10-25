<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Crypto\FileCipher;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\content_relay_test\FakeOrigin;
use Drupal\file\Entity\File;
use Drupal\Tests\content_relay\Traits\LargeFileTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a 500 MB file arriving within a 256 MB memory limit.
 *
 * Set CONTENT_RELAY_LARGE_FILE_MB to try another size.
 *
 * @group content_relay
 * @group content_relay_slow
 */
#[Group('content_relay')]
#[Group('content_relay_slow')]
#[RunTestsInSeparateProcesses]
final class LargeFileTest extends ReplicaTestBase {

  use LargeFileTrait;

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
    $this->useRealFileDirectories();
    $this->useFakeOrigin();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeLargeFiles();
    parent::tearDown();
  }

  /**
   * Plain and encrypted downloads.
   */
  public static function encryption(): array {
    return ['plain' => [FALSE], 'encrypted' => [TRUE]];
  }

  /**
   * The file is fetched, decrypted, checked and stored a chunk at a time.
   *
   * @dataProvider encryption
   */
  #[DataProvider('encryption')]
  public function testLargeFileArrives(bool $encrypted): void {
    $bytes = $this->largeFileSize();
    // The file as the origin has it; its copy then moves out of this site's
    // files, for the fake origin to serve.
    $this->writeLargeFile('public://large.bin', $bytes);
    $file = File::create(['uri' => 'public://large.bin', 'filename' => 'large.bin', 'status' => 1, 'uid' => 0]);
    $file->save();
    $snapshot = $this->snapshot($file);
    $source = $this->largeFileDirectory . '/origin.bin';
    $this->assertTrue(rename($this->container->get('file_system')->realpath('public://large.bin'), $source));
    $this->assertStringStartsWith($this->largeFileDirectory . '/public/', $this->container->get('file_system')->realpath('public://') . '/');
    $this->forget($file);
    $this->container->get('content_relay.file_checksums')->forget('public://large.bin');
    $key = $encrypted ? base64_encode(FileCipher::generateKey()) : NULL;
    $this->updateFakeOrigin(function (array &$config) use ($snapshot, $source, $key): void {
      $config['files'][$snapshot['uuid']] = array_filter([
        'path' => $source,
        'uri' => 'public://large.bin',
        'key' => $key,
      ]);
    });
    $meta = FakeOrigin::fileMetaFromPath($snapshot['uuid'], $source, 'public://large.bin', 0, $key);
    $this->apply($this->event($snapshot, Operation::Create, 1, ['revisionable' => FALSE, 'files' => [$meta]]));

    $limit = ini_get('memory_limit');
    ini_set('memory_limit', '256M');
    memory_reset_peak_usage();
    $baseline = memory_get_usage();
    try {
      $this->drainQueue(FileSync::QUEUE);
    }
    finally {
      $peak = memory_get_peak_usage() - $baseline;
      ini_set('memory_limit', $limit);
    }

    $this->assertSame(FileSync::DONE, $this->container->get('content_relay_replica.file_sync')->get($snapshot['uuid'])['status']);
    $this->assertSame($bytes, filesize('public://large.bin'));
    $this->assertSame($meta['sha256'], hash_file('sha256', 'public://large.bin'));
    $this->assertSame(SyncStatus::SYNCED, $this->container->get('content_relay_replica.sync_state')->get('file', $snapshot['uuid'])['status']);
    $this->assertLessThan(16 * 1024 * 1024, $peak, sprintf('Peak memory grew by %.1f MB for a %d MB file.', $peak / 1048576, $bytes / 1048576));
    // Nothing is left behind in the temporary directory.
    $this->assertSame([], array_values(array_diff(scandir($this->largeFileDirectory . '/tmp'), ['.', '..'])));
  }

}
