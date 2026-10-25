<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Controller;

use Drupal\content_relay\Auth\AuthenticationException;
use Drupal\content_relay\Auth\PullAuthenticatorInterface;
use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay_origin\File\FileAccess;
use Drupal\content_relay_origin\File\FileLinks;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files to replicas.
 *
 * - GET /content-relay/v1/files/{uuid}: fresh metadata and download link,
 *   for a replica authenticated with its short-lived JWT.
 * - GET /content-relay/v1/files/{uuid}/download: the file itself, for the
 *   holder of a valid signed link. Streamed from storage, never read into
 *   memory.
 *
 * Both serve a file only while the replica may have it; see FileAccess.
 */
final class FileController implements ContainerInjectionInterface {

  /**
   * The scope replicas' tokens must carry.
   */
  public const SCOPE = 'sync:read';

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly FileAccess $access,
    private readonly FileLinks $links,
    private readonly FileChecksums $checksums,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly PullAuthenticatorInterface $authenticator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity.repository'),
      $container->get('content_relay_origin.file_access'),
      $container->get('content_relay_origin.file_links'),
      $container->get('content_relay.file_checksums'),
      $container->get('file_url_generator'),
      $container->get('content_relay.pull_authenticator'),
      $container->get('logger.channel.content_relay_origin'),
    );
  }

  /**
   * Fresh metadata and a new download link.
   */
  public function info(string $uuid, Request $request): JsonResponse {
    $header = (string) $request->headers->get('Authorization', '');
    if (!str_starts_with($header, 'Bearer ')) {
      return $this->refuse(401, 'unauthenticated', 'A bearer token is required.');
    }
    try {
      $replica_id = $this->authenticator->authenticate(substr($header, 7), self::SCOPE);
    }
    catch (AuthenticationException $e) {
      $this->logger->warning('Refused a file request from @ip: @reason', [
        '@ip' => $request->getClientIp(),
        '@reason' => $e->reason,
      ]);
      return $this->refuse(401, $e->reason, $e->getMessage());
    }
    $file = $this->file($uuid);
    if ($file === NULL || !$this->access->allows($replica_id, $file)) {
      // The same answer for both, so a replica cannot probe for files.
      return $this->refuse(404, 'not_found', 'No such file for this replica.');
    }
    $checksum = $this->checksums->forUri($file->getFileUri());
    if ($checksum === NULL) {
      return $this->refuse(404, 'missing', 'The file is missing from storage on the origin.');
    }
    return new JsonResponse(self::metadata($file, $checksum, $this->links->build($file, $replica_id), $this->fileUrlGenerator), 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * The file, for a signed link.
   */
  public function download(string $uuid, Request $request): Response {
    $replica_id = (string) $request->query->get('replica', '');
    $problem = $this->links->check($uuid, $replica_id, (int) $request->query->get('expires', 0), (string) $request->query->get('key', ''), (string) $request->query->get('signature', ''));
    if ($problem === 'expired') {
      return $this->refuse(410, 'expired', 'The link has expired; ask for a new one.');
    }
    if ($problem !== NULL) {
      $this->logger->warning('Refused a file download from @ip: @reason', [
        '@ip' => $request->getClientIp(),
        '@reason' => $problem,
      ]);
      return $this->refuse(403, $problem, 'The link is not valid.');
    }
    $file = $this->file($uuid);
    if ($file === NULL || !$this->access->allows($replica_id, $file)) {
      return $this->refuse(404, 'not_found', 'No such file for this replica.');
    }
    $checksum = $this->checksums->forUri($file->getFileUri());
    if ($checksum === NULL) {
      return $this->refuse(404, 'missing', 'The file is missing from storage on the origin.');
    }
    $response = new BinaryFileResponse($file->getFileUri(), 200, [
      'Content-Type' => 'application/octet-stream',
      'Cache-Control' => 'private, no-store',
      'X-Content-Relay-Sha256' => $checksum['sha256'],
    ], FALSE);
    $response->setContentDisposition('attachment', 'file');
    return $response;
  }

  /**
   * What a replica is told about a file, in events and by the endpoint.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param array{sha256: string, size: int} $checksum
   *   Its checksum.
   * @param array{download: string, expires: int} $link
   *   A signed link.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $url_generator
   *   Builds the file's public URL.
   */
  public static function metadata(FileInterface $file, array $checksum, array $link, FileUrlGeneratorInterface $url_generator): array {
    return [
      'uuid' => (string) $file->uuid(),
      'uri' => $file->getFileUri(),
      'sha256' => $checksum['sha256'],
      'size' => $checksum['size'],
      'download' => $link['download'],
      'expires' => $link['expires'],
      // Where the file is on this site, root-relative, for replicas that
      // link to the origin's copy rather than their own.
      'url' => $url_generator->generateString($file->getFileUri()),
      'encryption' => 'none',
    ];
  }

  /**
   * A file by UUID.
   */
  private function file(string $uuid): ?FileInterface {
    /** @var \Drupal\Core\Entity\EntityInterface|null $file */
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * A refusal.
   */
  private function refuse(int $status, string $result, string $message): JsonResponse {
    return new JsonResponse(['result' => $result, 'message' => $message], $status, ['Cache-Control' => 'no-store']);
  }

}
