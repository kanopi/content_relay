<?php

declare(strict_types=1);

namespace Drupal\content_relay\File;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Maps URLs of this site's stored files to file URIs, and back.
 *
 * Recognizes public file URLs (under the public files path), private ones
 * (/system/files/…) and image style derivatives of either, which map to the
 * original file. Relative URLs, and absolute ones on this site's own host or
 * any domain in content_relay.settings:files.own_domains, are this site's;
 * anything else is external and never mapped.
 */
class FileUrlMapper {

  public function __construct(
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected RequestStack $requestStack,
  ) {}

  /**
   * The file URI and image style a URL points at, or NULL.
   *
   * @return array{uri: string, style: string|null}|null
   *   The URI of the original file, and the style when the URL is a
   *   derivative.
   */
  public function toUri(string $url): ?array {
    $parts = parse_url(trim($url));
    if ($parts === FALSE || !isset($parts['path'])) {
      return NULL;
    }
    if (isset($parts['host']) && !$this->isOwnHost($parts['host'])) {
      return NULL;
    }
    if (!isset($parts['host']) && (isset($parts['scheme']) || !str_starts_with($parts['path'], '/'))) {
      // mailto:, data:, or a path relative to the page: not a stored file.
      return NULL;
    }
    $path = rawurldecode($parts['path']);
    $base = $this->requestStack->getCurrentRequest()?->getBasePath() ?? '';
    if ($base !== '' && str_starts_with($path, $base . '/')) {
      $path = substr($path, strlen($base));
    }

    $roots = [
      '/' . trim(PublicStream::basePath(), '/') . '/' => 'public',
      '/system/files/' => 'private',
    ];
    foreach ($roots as $root => $scheme) {
      if (!str_starts_with($path, $root)) {
        continue;
      }
      $rest = substr($path, strlen($root));
      if (preg_match('#^styles/([a-z0-9_]+)/(public|private)/(.+)$#', $rest, $m)) {
        return ['uri' => $m[2] . '://' . $m[3], 'style' => $m[1]];
      }
      return $rest === '' ? NULL : ['uri' => $scheme . '://' . $rest, 'style' => NULL];
    }
    return NULL;
  }

  /**
   * This site's URL for a file or one of its derivatives, root-relative.
   */
  public function toUrl(string $uri, ?string $style = NULL): string {
    if ($style !== NULL && $this->entityTypeManager->hasDefinition('image_style')) {
      /** @var \Drupal\image\ImageStyleInterface|null $image_style */
      $image_style = $this->entityTypeManager->getStorage('image_style')->load($style);
      if ($image_style !== NULL) {
        return $this->fileUrlGenerator->transformRelative($image_style->buildUrl($uri));
      }
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Whether a host name is this site's.
   */
  protected function isOwnHost(string $host): bool {
    $host = strtolower($host);
    if ($host === strtolower((string) $this->requestStack->getCurrentRequest()?->getHost())) {
      return TRUE;
    }
    $own = (array) $this->configFactory->get('content_relay.settings')->get('files.own_domains');
    return in_array($host, array_map('strtolower', $own), TRUE);
  }

}
