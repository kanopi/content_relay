<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\File;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_relay\Crypto\FileCipher;
use Drupal\content_relay\File\FileChecksums;
use Drupal\content_relay\File\FileUrlMapper;
use Drupal\content_relay\Serialization\EntitySerializer;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\content_relay_replica\ActivePairing;
use Drupal\content_relay_replica\LocalChanges;
use Drupal\content_relay_replica\OriginClient;
use Drupal\content_relay_replica\OriginPeer;
use Drupal\content_relay_replica\ReceivingSettings;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Binaries of synced files: fetched apart from their entities, verified.
 *
 * A file entity is saved as soon as its event arrives; its binary is fetched
 * by the content_relay_replica_files queue through the file transport,
 * decrypted when the contract encrypts files (with the per-file key from the
 * sealed event, kept only until the file is in place), checked against the
 * origin's SHA-256, and only then moved into place. A
 * binary already here with the right checksum is not fetched again. Failed
 * fetches are retried with backoff; an expired link is renewed through the
 * origin's file endpoint.
 *
 * Items using files that have not arrived wait (status waiting_files). With
 * files.pending set to "hold" they are also unpublished meanwhile. When the
 * last of their files arrives they are republished as received and their
 * hash is checked.
 */
class FileSync {

  public const TABLE = 'content_relay_file';
  public const WAIT_TABLE = 'content_relay_file_wait';
  public const QUEUE = 'content_relay_replica_files';

  public const PENDING = 'pending';
  public const DONE = 'done';
  public const ERROR = 'error';

  public function __construct(
    protected Connection $database,
    protected QueueFactory $queueFactory,
    protected FileTransportInterface $transport,
    protected OriginClient $originClient,
    protected OriginPeer $origin,
    protected FileChecksums $checksums,
    protected FileSystemInterface $fileSystem,
    protected FileUrlMapper $urlMapper,
    protected EntityRepositoryInterface $entityRepository,
    protected SyncStateStore $syncState,
    protected EntitySerializer $serializer,
    protected LocalChanges $localChanges,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected ReceivingSettings $receiving,
    protected ActivePairing $active,
  ) {}

  /**
   * Records a file entity just saved from an event, and queues its binary.
   *
   * @param \Drupal\file\FileInterface $file
   *   The local file entity.
   * @param array $meta
   *   The event's metadata for it: sha256, size, download, expires, url, uri.
   *
   * @return string
   *   self::DONE when the binary is already here, otherwise self::PENDING.
   */
  public function register(FileInterface $file, array $meta): string {
    $done = $this->matches($file->getFileUri(), (string) $meta['sha256']);
    $this->database->merge(self::TABLE)
      ->keys(['uuid' => (string) $file->uuid()])
      ->fields([
        'pairing' => $this->active->id(),
        'uri' => $file->getFileUri(),
        'origin_uri' => (string) ($meta['uri'] ?? $file->getFileUri()),
        'sha256' => (string) $meta['sha256'],
        'size' => (int) ($meta['size'] ?? 0),
        'download' => $meta['download'] ?? NULL,
        'expires' => isset($meta['expires']) ? (int) $meta['expires'] : NULL,
        'url' => $meta['url'] ?? NULL,
        'cipher' => $done ? NULL : self::encryptionFields($meta)['cipher'],
        'file_key' => $done ? NULL : self::encryptionFields($meta)['file_key'],
        'status' => $done ? self::DONE : self::PENDING,
        'attempts' => 0,
        'next_attempt' => 0,
        'last_error' => NULL,
        'updated' => $this->time->getCurrentTime(),
      ])
      ->execute();
    if (!$done) {
      $this->queue((string) $file->uuid());
    }
    return $done ? self::DONE : self::PENDING;
  }

  /**
   * A file's record.
   */
  public function get(string $uuid): ?array {
    return $this->database->select(self::TABLE, 'f')->fields('f')->condition('uuid', $uuid)->execute()->fetchAssoc() ?: NULL;
  }

  /**
   * Forgets a file whose entity was deleted.
   */
  public function forget(string $uuid): void {
    $this->database->delete(self::TABLE)->condition('uuid', $uuid)->execute();
    $this->database->delete(self::WAIT_TABLE)->condition('file_uuid', $uuid)->execute();
  }

  /**
   * The files among some that have not arrived.
   *
   * @param string[] $uuids
   *   File UUIDs.
   *
   * @return string[]
   *   Those with a binary still to come or failed.
   */
  public function pendingAmong(array $uuids): array {
    if ($uuids === []) {
      return [];
    }
    return $this->database->select(self::TABLE, 'f')
      ->fields('f', ['uuid'])
      ->condition('uuid', array_values(array_unique($uuids)), 'IN')
      ->condition('status', self::DONE, '<>')
      ->execute()
      ->fetchCol();
  }

  /**
   * Queues a file's binary for fetching.
   */
  public function queue(string $uuid): void {
    $this->queueFactory->get(self::QUEUE)->createItem(['uuid' => $uuid]);
  }

  /**
   * Fetches a file's binary, verifies it and puts it in place.
   *
   * @return string
   *   The file's status afterwards.
   */
  public function process(string $uuid): string {
    // Fetched from the origin the file came from.
    $pairing = $this->get($uuid)['pairing'] ?? NULL;
    if ($pairing !== NULL && $this->active->current() !== $pairing) {
      return $this->active->run($pairing, fn () => $this->process($uuid));
    }
    $record = $this->get($uuid);
    if ($record === NULL) {
      return self::ERROR;
    }
    if ($this->matches($record['uri'], $record['sha256'])) {
      $this->complete($record);
      return self::DONE;
    }
    $renewed = FALSE;
    if ($record['download'] === NULL || (int) $record['expires'] <= $this->time->getCurrentTime()) {
      $record = $this->renew($uuid) ?? $record;
      $renewed = TRUE;
    }

    $temporary = $this->fileSystem->tempnam('temporary://', 'content_relay_');
    if ($temporary === FALSE) {
      return $this->fail($record, 'The temporary directory is not writable.');
    }
    try {
      try {
        $this->transport->fetch($record, $temporary);
      }
      catch (FileTransportException $e) {
        if ($e->reason !== FileTransportException::EXPIRED || $renewed) {
          throw $e;
        }
        // The link ran out while the download waited: get a new one.
        $record = $this->renew($uuid) ?? throw $e;
        $this->transport->fetch($record, $temporary);
      }
      if ($record['file_key'] !== NULL) {
        $temporary = $this->decrypt($record, $temporary);
      }
      $actual = hash_file('sha256', $temporary);
      if ($actual !== $record['sha256']) {
        return $this->fail($record, sprintf('The download does not match the origin checksum (got %s).', substr((string) $actual, 0, 12)));
      }
      $directory = $this->fileSystem->dirname($record['uri']);
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $this->fileSystem->move($temporary, $record['uri'], FileExists::Replace);
      $this->checksums->forget($record['uri']);
    }
    catch (FileTransportException $e) {
      return $this->fail($record, $e->getMessage(), $e->reason === FileTransportException::REFUSED);
    }
    catch (\Throwable $e) {
      return $this->fail($record, sprintf('%s: %s', get_class($e), $e->getMessage()));
    }
    finally {
      if (file_exists($temporary)) {
        @unlink($temporary);
      }
    }
    $this->complete($record);
    return self::DONE;
  }

  /**
   * Fetches a file now with a fresh link, whatever its status.
   *
   * @return string
   *   The file's status afterwards.
   */
  public function pull(string $uuid): string {
    // Fetched from the origin the file came from.
    $pairing = $this->get($uuid)['pairing'] ?? NULL;
    if ($pairing !== NULL && $this->active->current() !== $pairing) {
      return $this->active->run($pairing, fn () => $this->pull($uuid));
    }
    if ($this->get($uuid) === NULL) {
      /** @var \Drupal\Core\Entity\EntityInterface|null $file */
      $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
      if (!$file instanceof FileInterface || $this->syncState->get('file', $uuid) === NULL) {
        return self::ERROR;
      }
      // Synced before files were tracked: start a record from the origin.
      $this->database->insert(self::TABLE)->fields([
        'pairing' => $this->active->forSite((string) $this->syncState->get('file', $uuid)['source_site']) ?? $this->active->id(),
        'uuid' => $uuid,
        'uri' => $file->getFileUri(),
        'origin_uri' => $file->getFileUri(),
        'sha256' => str_repeat('0', 64),
        'size' => 0,
        'status' => self::PENDING,
        'updated' => $this->time->getCurrentTime(),
      ])->execute();
    }
    $this->database->update(self::TABLE)
      ->fields(['status' => self::PENDING, 'attempts' => 0, 'next_attempt' => 0, 'expires' => 0])
      ->condition('uuid', $uuid)
      ->execute();
    if ($this->renew($uuid) === NULL) {
      return $this->fail($this->get($uuid), 'The origin did not give a fresh link.');
    }
    return $this->process($uuid);
  }

  /**
   * Queues the files an item uses that are missing here or corrupt.
   *
   * @return string[]
   *   UUIDs of the files queued.
   */
  public function queueMissingFor(ContentEntityInterface $entity): array {
    $uuids = $entity instanceof FileInterface ? [(string) $entity->uuid()] : $this->filesUsedBy($entity);
    $queued = [];
    foreach ($uuids as $uuid) {
      $record = $this->get($uuid);
      if ($record !== NULL && $this->matches($record['uri'], $record['sha256'])) {
        continue;
      }
      $this->resetForPull($uuid);
      $this->queue($uuid);
      $queued[] = $uuid;
    }
    return $queued;
  }

  /**
   * Queues every synced file missing here or not matching its checksum.
   *
   * @return string[]
   *   UUIDs of the files queued.
   */
  public function verifyAll(): array {
    $queued = [];
    foreach ($this->database->select(self::TABLE, 'f')->fields('f')->condition('status', self::DONE)->execute()->fetchAll() as $row) {
      $record = (array) $row;
      if (!$this->matches($record['uri'], $record['sha256'])) {
        $this->logger->warning('Synced file @uri is missing or altered; fetching it again.', ['@uri' => $record['uri']]);
        $this->resetForPull($record['uuid']);
        $this->queue($record['uuid']);
        $queued[] = $record['uuid'];
      }
    }
    return $queued;
  }

  /**
   * Queues retries that are due. For cron.
   *
   * @return int
   *   How many were queued.
   */
  public function scheduleDue(): int {
    $due = $this->database->select(self::TABLE, 'f')
      ->fields('f', ['uuid'])
      ->condition('status', self::PENDING)
      ->condition('attempts', 0, '>')
      ->condition('next_attempt', $this->time->getCurrentTime(), '<=')
      ->execute()
      ->fetchCol();
    foreach ($due as $uuid) {
      $this->queue($uuid);
    }
    return count($due);
  }

  /**
   * Records that an item waits for files, and holds it if configured.
   *
   * Call after the item is saved, inside the same transaction.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The item, as saved.
   * @param string[] $file_uuids
   *   Files it waits for.
   */
  public function waitFor(ContentEntityInterface $entity, array $file_uuids): void {
    $held = [];
    if ($this->settings('pending') === 'hold' && $entity instanceof EntityPublishedInterface) {
      foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
        $translation = $entity->getTranslation($langcode);
        if ($translation->isPublished()) {
          $translation->setUnpublished();
          $held[] = (string) $langcode;
        }
      }
      if ($held !== []) {
        $entity->setSyncing(TRUE);
        if ($entity->getEntityType()->isRevisionable()) {
          $entity->setNewRevision(FALSE);
        }
        $entity->save();
      }
    }
    foreach ($file_uuids as $file_uuid) {
      $this->database->merge(self::WAIT_TABLE)
        ->keys([
          'entity_type' => $entity->getEntityTypeId(),
          'uuid' => (string) $entity->uuid(),
          'file_uuid' => $file_uuid,
        ])
        ->fields(['held' => $held === [] ? NULL : json_encode($held, \JSON_THROW_ON_ERROR)])
        ->execute();
    }
  }

  /**
   * The URL a file token in text becomes here.
   *
   * @return \Closure(string, string|null): (string|null)
   *   For DenormalizationContext::$fileUrl.
   */
  public function urlCallback(): \Closure {
    return function (string $uuid, ?string $style): ?string {
      if ($this->settings('links') === 'origin' && ($origin = $this->originUrl($uuid, $style)) !== NULL) {
        return $origin;
      }
      /** @var \Drupal\Core\Entity\EntityInterface|null $file */
      $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
      return $file instanceof FileInterface ? $this->urlMapper->toUrl($file->getFileUri(), $style) : NULL;
    };
  }

  /**
   * Maps the origin's file URLs back to files, when links point there.
   *
   * @return (\Closure(string): (array{uuid: string, style: string|null}|null))|null
   *   For NormalizationContext::$fileUrlResolver; NULL when links are local.
   */
  public function originUrlResolver(): ?\Closure {
    return $this->localChanges->originUrlResolver();
  }

  /**
   * A file's absolute URL on the origin, or one of its derivatives.
   */
  public function originUrl(string $uuid, ?string $style = NULL): ?string {
    // Fetched from the origin the file came from.
    $pairing = $this->get($uuid)['pairing'] ?? NULL;
    if ($pairing !== NULL && $this->active->current() !== $pairing) {
      return $this->active->run($pairing, fn () => $this->originUrl($uuid, $style));
    }
    $origin = $this->origin->get();
    $record = $this->get($uuid);
    if ($origin === NULL || $record === NULL || !is_string($record['url']) || $record['url'] === '') {
      return NULL;
    }
    $path = $record['url'];
    if ($style !== NULL) {
      [$scheme, $target] = explode('://', $record['origin_uri'], 2) + [1 => ''];
      $path = $scheme === 'private'
        ? '/system/files/styles/' . $style . '/private/' . $target
        : substr($path, 0, strlen($path) - strlen($target)) . 'styles/' . $style . '/' . $scheme . '/' . $target;
    }
    return rtrim($origin['url'], '/') . $path;
  }

  /**
   * Asks the origin for fresh metadata and a new link.
   *
   * @return array|null
   *   The updated record, or NULL when the origin would not say.
   */
  protected function renew(string $uuid): ?array {
    try {
      $info = $this->originClient->fileInfo($uuid);
    }
    catch (SubscriptionException $e) {
      $this->logger->warning('A fresh link for file @uuid could not be had: @message', [
        '@uuid' => $uuid,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
    $this->database->update(self::TABLE)
      ->fields([
        'sha256' => (string) $info['sha256'],
        'size' => (int) $info['size'],
        'download' => (string) $info['download'],
        'expires' => (int) $info['expires'],
        'url' => $info['url'] ?? NULL,
        'origin_uri' => (string) $info['uri'],
        'updated' => $this->time->getCurrentTime(),
      ] + self::encryptionFields($info))
      ->condition('uuid', $uuid)
      ->execute();
    return $this->get($uuid);
  }

  /**
   * The encryption columns for file metadata from the origin.
   *
   * @return array{cipher: string|null, file_key: string|null}
   *   Column values.
   */
  protected static function encryptionFields(array $meta): array {
    $encryption = $meta['encryption'] ?? 'none';
    if (!is_array($encryption) || !is_string($encryption['key'] ?? NULL)) {
      return ['cipher' => NULL, 'file_key' => NULL];
    }
    return ['cipher' => (string) ($encryption['cipher'] ?? ''), 'file_key' => $encryption['key']];
  }

  /**
   * Decrypts a downloaded file into a new temporary file.
   *
   * @return string
   *   The decrypted file; the encrypted one is removed.
   *
   * @throws \RuntimeException
   *   When it cannot be decrypted.
   */
  protected function decrypt(array $record, string $encrypted): string {
    if ($record['cipher'] !== FileCipher::NAME) {
      throw new \RuntimeException(sprintf('Unknown file cipher "%s".', $record['cipher']));
    }
    $key = base64_decode((string) $record['file_key'], TRUE);
    $plain = $this->fileSystem->tempnam('temporary://', 'content_relay_');
    if ($key === FALSE || $plain === FALSE) {
      throw new \RuntimeException('The file cannot be decrypted.');
    }
    $in = fopen($encrypted, 'rb');
    $out = fopen($plain, 'wb');
    try {
      FileCipher::decrypt($in, $out, $key);
    }
    finally {
      fclose($in);
      fclose($out);
      @unlink($encrypted);
    }
    return $plain;
  }

  /**
   * Marks a file to be fetched again from scratch.
   */
  protected function resetForPull(string $uuid): void {
    $this->database->update(self::TABLE)
      ->fields(['status' => self::PENDING, 'attempts' => 0, 'next_attempt' => 0])
      ->condition('uuid', $uuid)
      ->execute();
  }

  /**
   * Records a failed attempt, scheduling a retry or giving up.
   *
   * @return string
   *   The file's status afterwards.
   */
  protected function fail(?array $record, string $message, bool $permanent = FALSE): string {
    if ($record === NULL) {
      return self::ERROR;
    }
    $attempts = (int) $record['attempts'] + 1;
    $give_up = $permanent || $attempts >= max(1, (int) $this->settings('max_attempts'));
    $base = max(1, (int) $this->settings('retry_base'));
    $this->database->update(self::TABLE)
      ->fields([
        'status' => $give_up ? self::ERROR : self::PENDING,
        'attempts' => $attempts,
        'next_attempt' => $this->time->getCurrentTime() + (int) min(86400, $base * 2 ** ($attempts - 1)),
        'last_error' => $message,
        'updated' => $this->time->getCurrentTime(),
      ])
      ->condition('uuid', $record['uuid'])
      ->execute();
    $this->logger->{$give_up ? 'error' : 'warning'}('Fetching file @uuid failed (attempt @attempts@final): @message', [
      '@uuid' => $record['uuid'],
      '@attempts' => $attempts,
      '@final' => $give_up ? ', giving up' : '',
      '@message' => $message,
    ]);
    if ($give_up) {
      foreach ($this->waiting($record['uuid']) as $item) {
        $this->syncState->setStatus($item['entity_type'], $item['uuid'], SyncStatus::WAITING_FILES, sprintf('File %s could not be fetched: %s', $record['uuid'], $message));
      }
    }
    return $give_up ? self::ERROR : self::PENDING;
  }

  /**
   * Marks a file arrived and finishes the items that were waiting for it.
   */
  protected function complete(array $record): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => self::DONE,
        'last_error' => NULL,
        'file_key' => NULL,
        'cipher' => NULL,
        'updated' => $this->time->getCurrentTime(),
      ])
      ->condition('uuid', $record['uuid'])
      ->execute();
    foreach ($this->waiting($record['uuid']) as $item) {
      $this->database->delete(self::WAIT_TABLE)
        ->condition('entity_type', $item['entity_type'])
        ->condition('uuid', $item['uuid'])
        ->condition('file_uuid', $record['uuid'])
        ->execute();
      $remaining = $this->database->select(self::WAIT_TABLE, 'w')
        ->condition('entity_type', $item['entity_type'])
        ->condition('uuid', $item['uuid'])
        ->countQuery()
        ->execute()
        ->fetchField();
      if ((int) $remaining === 0) {
        $this->finish($item['entity_type'], $item['uuid'], $item['held'] === NULL ? [] : (json_decode($item['held'], TRUE) ?: []));
      }
    }
  }

  /**
   * Items waiting for a file.
   *
   * @return array[]
   *   Rows with entity_type, uuid and held.
   */
  protected function waiting(string $file_uuid): array {
    return array_map(fn (object $row) => (array) $row, $this->database->select(self::WAIT_TABLE, 'w')
      ->fields('w', ['entity_type', 'uuid', 'held'])
      ->condition('file_uuid', $file_uuid)
      ->execute()
      ->fetchAll());
  }

  /**
   * Republishes a held item and checks its hash, now its files are here.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $uuid
   *   UUID.
   * @param string[] $held
   *   Translations unpublished while waiting.
   */
  protected function finish(string $entity_type_id, string $uuid, array $held): void {
    $state = $this->syncState->get($entity_type_id, $uuid);
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    if ($state === NULL || !$entity instanceof ContentEntityInterface || $state['status'] !== SyncStatus::WAITING_FILES) {
      return;
    }
    if ($held !== [] && $entity instanceof EntityPublishedInterface) {
      foreach ($held as $langcode) {
        if ($entity->hasTranslation($langcode)) {
          $entity->getTranslation($langcode)->setPublished();
        }
      }
      $entity->setSyncing(TRUE);
      if ($entity->getEntityType()->isRevisionable()) {
        $entity->setNewRevision(FALSE);
      }
      $entity->save();
    }
    $local = $this->localChanges->hashFor($entity, $state);
    $expected = $state['expected_hash'] ?? $state['source_hash'];
    $synced = hash_equals((string) $expected, $local->entity);
    $this->syncState->save($entity_type_id, $uuid, [
      'local_hash' => $local->entity,
      'local_translation_hashes' => $local->translations,
      'status' => $synced ? SyncStatus::SYNCED : SyncStatus::ERROR,
      'last_error' => $synced ? NULL : 'With its files in place, the local copy does not hash the same as the origin\'s.',
      'last_synced' => (int) $state['last_synced'],
    ]);
  }

  /**
   * The files an item uses, in fields or text.
   *
   * @return string[]
   *   File UUIDs.
   */
  protected function filesUsedBy(ContentEntityInterface $entity): array {
    $context = new NormalizationContext();
    $context->fileUrlResolver = $this->originUrlResolver();
    $this->serializer->normalize($entity, $context);
    $uuids = [];
    foreach ($context->references as $reference) {
      if ($reference['entity_type'] === 'file') {
        $uuids[] = $reference['uuid'];
      }
    }
    return $uuids;
  }

  /**
   * Whether the file at a URI has a checksum.
   */
  protected function matches(string $uri, string $sha256): bool {
    return ($this->checksums->forUri($uri)['sha256'] ?? NULL) === $sha256;
  }

  /**
   * A files setting.
   */
  protected function settings(string $key): mixed {
    return $this->receiving->get('files.' . $key);
  }

}
