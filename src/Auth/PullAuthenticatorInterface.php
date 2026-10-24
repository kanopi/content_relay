<?php

declare(strict_types=1);

namespace Drupal\content_relay\Auth;

/**
 * Authenticates requests a replica makes to its origin.
 *
 * Pulls, schema and file requests are ordinary GETs, not signed messages, so
 * they carry a bearer token instead. This interface is what the endpoints
 * depend on, so a different scheme (OAuth client credentials through Simple
 * OAuth, for example) can replace the default self-issued JWTs.
 */
interface PullAuthenticatorInterface {

  /**
   * Issues a token for a request to a peer.
   *
   * @param string $audience
   *   The site ID of the peer that will receive it.
   * @param string $scope
   *   What the token allows, such as "sync:read".
   *
   * @return string
   *   The token, for an "Authorization: Bearer" header.
   */
  public function issue(string $audience, string $scope): string;

  /**
   * Checks a token presented to this site.
   *
   * @param string $token
   *   The token.
   * @param string $scope
   *   The scope the endpoint requires.
   *
   * @return string
   *   The site ID of the peer that issued it.
   *
   * @throws \Drupal\content_relay\Auth\AuthenticationException
   *   When the token must be refused.
   */
  public function authenticate(string $token, string $scope): string;

}
