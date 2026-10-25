<?php

declare(strict_types=1);

namespace Drupal\content_relay\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Optional lists of addresses a peer may connect from.
 *
 * An empty list allows every address: the signature on each message is
 * what authenticates a peer, and the list is a second line of defense.
 */
final class IpAllowlist {

  /**
   * Whether an address is on a list.
   *
   * @param string|null $ip
   *   The client address.
   * @param string[] $allowed
   *   Addresses and CIDR ranges, IPv4 or IPv6. Empty for any.
   */
  public static function allows(?string $ip, array $allowed): bool {
    return $allowed === [] || ($ip !== NULL && IpUtils::checkIp($ip, $allowed));
  }

  /**
   * Parses a list typed one entry per line or comma-separated.
   *
   * @return string[]
   *   Entries.
   *
   * @throws \InvalidArgumentException
   *   When an entry is not an address or range.
   */
  public static function parse(string $text): array {
    $entries = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $text) ?: [])));
    foreach ($entries as $entry) {
      [$address, $bits] = explode('/', $entry, 2) + [1 => NULL];
      $ok = filter_var($address, FILTER_VALIDATE_IP) !== FALSE
        && ($bits === NULL || (ctype_digit($bits) && (int) $bits <= (str_contains($address, ':') ? 128 : 32)));
      if (!$ok) {
        throw new \InvalidArgumentException(sprintf('"%s" is not an IP address or range.', $entry));
      }
    }
    return $entries;
  }

}
