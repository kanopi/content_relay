<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\File;

use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\ReceivingSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Downloads files from the origin's signed links, streamed to disk.
 */
class HttpFileTransport implements FileTransportInterface {

  public function __construct(
    protected ClientInterface $httpClient,
    protected OriginPeer $origin,
    protected ConfigFactoryInterface $configFactory,
    protected ReceivingSettings $receiving,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fetch(array $file, string $destination): void {
    $origin = $this->origin->get();
    if ($origin === NULL || !is_string($file['download'] ?? NULL) || $file['download'] === '') {
      throw new FileTransportException(FileTransportException::EXPIRED, 'There is no download link.');
    }
    try {
      $response = $this->httpClient->request('GET', rtrim($origin['url'], '/') . $file['download'], [
        'sink' => $destination,
        'http_errors' => FALSE,
        'allow_redirects' => FALSE,
        'timeout' => (int) ($this->receiving->get('files.timeout') ?: 300),
        'connect_timeout' => 10,
      ]);
    }
    catch (GuzzleException $e) {
      throw new FileTransportException(FileTransportException::TRANSIENT, sprintf('The origin could not be reached: %s', $e->getMessage()), $e);
    }
    $status = $response->getStatusCode();
    if ($status === 200) {
      return;
    }
    // An error answer was written where the file would have gone.
    $answer = json_decode((string) @file_get_contents($destination, length: 4096), TRUE);
    $result = is_array($answer) && is_string($answer['result'] ?? NULL) ? $answer['result'] : NULL;
    $reason = match (TRUE) {
      $status === 410 || $result === 'expired' => FileTransportException::EXPIRED,
      $status === 408 || $status === 429 || $status >= 500 => FileTransportException::TRANSIENT,
      default => FileTransportException::REFUSED,
    };
    throw new FileTransportException($reason, sprintf('The origin answered HTTP %d%s.', $status, $result === NULL ? '' : " ($result)"));
  }

}
