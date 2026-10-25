<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\FileCipher;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\key\Entity\Key;
use Drupal\node\Entity\Node;
use Drupal\Tests\content_relay\Traits\LargeFileTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a 500 MB file leaving the origin within a 256 MB memory limit.
 *
 * Set CONTENT_RELAY_LARGE_FILE_MB to try another size.
 *
 * @group content_relay
 * @group content_relay_slow
 */
#[Group('content_relay')]
#[Group('content_relay_slow')]
#[RunTestsInSeparateProcesses]
final class LargeFileTest extends OriginTestBase {

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
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_file', 'type' => 'file'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_file'])->save();

    [$encryption, $material] = KeyPair::generate(KeyPurpose::Encryption);
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
    // Paired with encryption, so files are encrypted on the way out.
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code, ['node' => ['article']], [
      'requested' => ['bundles' => ['node' => ['article']], 'encryption' => 'sealed'] + SubscriptionContract::defaultReplicaTerms(),
    ]));
    $this->replica->originEncryptionKey = ['id' => $encryption->id(), 'public_key' => $encryption->publicKey];
    $this->replica->seal = TRUE;
    $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('CONTENT_RELAY_ORIGIN_TEST_ENCRYPTION');
    $this->removeLargeFiles();
    parent::tearDown();
  }

  /**
   * The file is hashed on save and encrypted on download a chunk at a time.
   */
  public function testLargeFileLeaves(): void {
    $bytes = $this->largeFileSize();
    $this->writeLargeFile('public://large.bin', $bytes);
    $sha256 = hash_file('sha256', 'public://large.bin');

    $limit = ini_get('memory_limit');
    ini_set('memory_limit', '256M');
    memory_reset_peak_usage();
    $baseline = memory_get_usage();
    try {
      $file = File::create(['uri' => 'public://large.bin', 'filename' => 'large.bin', 'status' => 1]);
      $file->save();
      Node::create([
        'type' => 'article',
        'title' => 'Large',
        'uid' => 0,
        'field_file' => ['target_id' => $file->id(), 'display' => 1],
      ])->save();
      $this->deliver();
      $meta = $this->replica->events[0]['files'][0];

      // The response is sent as a web server would, into a file here.
      $response = $this->container->get('http_kernel')->handle(Request::create($meta['download']));
      $encrypted = $this->largeFileDirectory . '/download.enc';
      $out = fopen($encrypted, 'wb');
      ob_start(function (string $chunk) use ($out): string {
        fwrite($out, $chunk);
        return '';
      }, 1024 * 1024);
      $response->sendContent();
      ob_end_clean();
      fclose($out);
    }
    finally {
      $peak = memory_get_peak_usage() - $baseline;
      ini_set('memory_limit', $limit);
    }

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([$sha256, $bytes], [$meta['sha256'], $meta['size']]);
    $this->assertSame(FileCipher::NAME, $meta['encryption']['cipher']);
    $this->assertLessThan(16 * 1024 * 1024, $peak, sprintf('Peak memory grew by %.1f MB for a %d MB file.', $peak / 1048576, $bytes / 1048576));

    // What was sent is the file, encrypted with the key in the event.
    $this->assertNotSame($sha256, hash_file('sha256', $encrypted));
    $in = fopen($encrypted, 'rb');
    $out = fopen($this->largeFileDirectory . '/download.bin', 'wb');
    FileCipher::decrypt($in, $out, base64_decode($meta['encryption']['key']));
    fclose($in);
    fclose($out);
    $this->assertSame($sha256, hash_file('sha256', $this->largeFileDirectory . '/download.bin'));
  }

}
