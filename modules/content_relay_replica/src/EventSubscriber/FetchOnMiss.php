<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\EventSubscriber;

use Drupal\content_relay\File\FileUrlMapper;
use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns a "not found" for a synced file that is missing here into a fetch.
 *
 * A request for a public or private file, or an image style derivative of
 * one, that this site should have from the origin but does not, queues the
 * file to be fetched and, per files.fetch_on_miss, either redirects to the
 * origin's copy or answers with a placeholder (a transparent pixel for
 * images, a "try again shortly" for anything else) instead of a plain 404.
 */
final class FetchOnMiss implements EventSubscriberInterface {

  /**
   * A 1×1 transparent GIF.
   */
  private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

  public function __construct(
    private readonly FileUrlMapper $urlMapper,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SyncStateStore $syncState,
    private readonly FileSync $files,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 50]];
  }

  /**
   * Replaces a 404 for a missing synced file.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || $event->getResponse()->getStatusCode() !== 404) {
      return;
    }
    $mode = (string) $this->configFactory->get('content_relay_replica.settings')->get('files.fetch_on_miss');
    if ($mode !== 'redirect' && $mode !== 'placeholder') {
      return;
    }
    $mapped = $this->urlMapper->toUri($event->getRequest()->getPathInfo());
    if ($mapped === NULL) {
      return;
    }
    /** @var \Drupal\file\FileInterface[] $found */
    $found = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $mapped['uri']]);
    $file = reset($found);
    if (!$file || $this->syncState->get('file', (string) $file->uuid()) === NULL || is_file($mapped['uri'])) {
      return;
    }
    $this->files->queueMissingFor($file);

    $origin = $mode === 'redirect' ? $this->files->originUrl((string) $file->uuid(), $mapped['style']) : NULL;
    if ($origin !== NULL) {
      $response = new TrustedRedirectResponse($origin, 307);
      $response->getCacheableMetadata()->setCacheMaxAge(0);
    }
    elseif (str_starts_with((string) $file->getMimeType(), 'image/')) {
      $response = new Response((string) base64_decode(self::PIXEL), 200, ['Content-Type' => 'image/gif']);
    }
    else {
      $response = new Response('This file is being fetched. Try again shortly.', 503, [
        'Content-Type' => 'text/plain',
        'Retry-After' => '30',
      ]);
    }
    $response->headers->set('Cache-Control', 'no-store');
    $event->setResponse($response);
  }

}
