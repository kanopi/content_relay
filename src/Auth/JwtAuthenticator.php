<?php

declare(strict_types=1);

namespace Drupal\content_relay\Auth;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Crypto\ReplayGuardInterface;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\SiteIdentity;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;

/**
 * Short-lived JWTs each site issues itself, signed with its Ed25519 key.
 *
 * A replica signs a token for its origin with the same key it signs messages
 * with; the origin checks it against the key it registered at pairing. There
 * is no shared secret and nothing to provision beyond pairing.
 *
 * Claims: iss (issuer site ID), aud (receiver site ID), iat, exp, jti, scope,
 * and a kid header naming the signing key. Tokens live at most five minutes,
 * and each jti is accepted once.
 *
 * Parsing and validation are the lcobucci/jwt library's; nothing here reads a
 * token by hand.
 */
class JwtAuthenticator implements PullAuthenticatorInterface {

  /**
   * How long issued tokens are valid, in seconds.
   */
  public const LIFETIME = 120;

  /**
   * The longest lifetime accepted, in seconds.
   */
  public const MAX_LIFETIME = 300;

  /**
   * Clock difference tolerated, in seconds.
   */
  public const LEEWAY = 30;

  public function __construct(
    protected KeyStore $keyStore,
    protected SiteIdentity $siteIdentity,
    protected PublicKeyResolverInterface $publicKeys,
    protected ReplayGuardInterface $replayGuard,
    protected TimeInterface $time,
    protected UuidInterface $uuid,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function issue(string $audience, string $scope): string {
    $key = $this->keyStore->getPrimary(KeyPurpose::Signing);
    $now = (new DrupalClock($this->time))->now();
    $token = Builder::new(new JoseEncoder(), ChainedFormatter::default())
      ->issuedBy($this->siteIdentity->getSiteId())
      ->permittedFor($audience)
      ->identifiedBy($this->uuid->generate())
      ->issuedAt($now)
      ->canOnlyBeUsedAfter($now)
      ->expiresAt($now->modify('+' . self::LIFETIME . ' seconds'))
      ->withClaim('scope', $scope)
      ->withHeader('kid', $key->id());
    return $key->withSecret(fn (string $secret) => $token->getToken(new Eddsa(), InMemory::plainText($secret))->toString());
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(string $token, string $scope): string {
    try {
      $parsed = (new Parser(new JoseEncoder()))->parse($token);
    }
    catch (\Throwable $e) {
      throw new AuthenticationException('malformed', 'The token cannot be parsed.', $e);
    }
    if (!$parsed instanceof UnencryptedToken) {
      throw new AuthenticationException('malformed', 'The token cannot be parsed.');
    }
    $claims = $parsed->claims();
    $issuer = $claims->get('iss');
    $key_id = $parsed->headers()->get('kid');
    if (!is_string($issuer) || !is_string($key_id)) {
      throw new AuthenticationException('malformed', 'The token has no issuer or key ID.');
    }
    $public = $this->publicKeys->getPublicKey($issuer, KeyPurpose::Signing, $key_id);
    if ($public === NULL) {
      throw new AuthenticationException('unknown_key', 'The token was signed with a key this site does not accept.');
    }

    try {
      (new Validator())->assert(
        $parsed,
        new SignedWith(new Eddsa(), InMemory::plainText($public)),
        new PermittedFor($this->siteIdentity->getSiteId()),
        new StrictValidAt(new DrupalClock($this->time), new \DateInterval('PT' . self::LEEWAY . 'S')),
      );
    }
    catch (RequiredConstraintsViolated $e) {
      // A bad signature means nothing else in the token can be believed, so
      // it is the reason given whatever else failed.
      $failed = array_map(fn ($violation) => $violation->constraint, $e->violations());
      $reason = match (TRUE) {
        in_array(SignedWith::class, $failed, TRUE) => 'bad_signature',
        in_array(PermittedFor::class, $failed, TRUE) => 'wrong_audience',
        in_array(StrictValidAt::class, $failed, TRUE) => 'expired',
        default => 'invalid',
      };
      throw new AuthenticationException($reason, 'The token is not valid here: ' . $e->getMessage(), $e);
    }

    $issued = $claims->get('iat');
    $expires = $claims->get('exp');
    if ($expires->getTimestamp() - $issued->getTimestamp() > self::MAX_LIFETIME) {
      throw new AuthenticationException('lifetime', sprintf('Tokens may live at most %d seconds.', self::MAX_LIFETIME));
    }
    if ($claims->get('scope') !== $scope) {
      throw new AuthenticationException('wrong_scope', sprintf('This request needs scope "%s".', $scope));
    }
    $jti = $claims->get('jti');
    if (!is_string($jti) || !$this->replayGuard->claim('jwt:' . $issuer, $jti, $expires->getTimestamp() + self::LEEWAY)) {
      throw new AuthenticationException('replayed', 'The token was already used.');
    }
    return $issuer;
  }

}
