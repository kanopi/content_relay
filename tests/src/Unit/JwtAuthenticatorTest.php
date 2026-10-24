<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Auth\AuthenticationException;
use Drupal\content_relay\Auth\JwtAuthenticator;
use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Crypto\ReplayGuardInterface;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\SiteIdentity;
use Drupal\Tests\UnitTestCase;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the self-issued JWTs replicas use for requests to the origin.
 *
 * Two authenticators stand for the two sites: the replica issues, the origin
 * authenticates, each with its own identity and clock.
 *
 * @group content_relay
 * @coversDefaultClass \Drupal\content_relay\Auth\JwtAuthenticator
 */
#[Group('content_relay')]
#[CoversClass(JwtAuthenticator::class)]
final class JwtAuthenticatorTest extends UnitTestCase {

  private const ORIGIN = 'origin-site';
  private const REPLICA = 'replica-site';

  /**
   * The replica's signing key.
   */
  private KeyPair $key;

  /**
   * The current time on both sites.
   */
  private int $now = 1700000000;

  /**
   * Token IDs the origin has seen.
   *
   * @var array<string, true>
   */
  private array $seen = [];

  /**
   * Keys the origin knows for the replica, by ID.
   *
   * @var array<string, string>
   */
  private array $known = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    [$this->key] = KeyPair::generate(KeyPurpose::Signing);
    $this->known[$this->key->id()] = $this->key->publicKey;
  }

  /**
   * An authenticator for a site.
   */
  private function site(string $site_id): JwtAuthenticator {
    $keys = $this->createMock(KeyStore::class);
    $keys->method('getPrimary')->willReturn($this->key);
    $identity = $this->createMock(SiteIdentity::class);
    $identity->method('getSiteId')->willReturn($site_id);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $this->now);
    $resolver = new class($this->known) implements PublicKeyResolverInterface {

      public function __construct(private array &$known) {}

      /**
       * {@inheritdoc}
       */
      public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string {
        return $site_id === 'replica-site' ? ($this->known[$key_id] ?? NULL) : NULL;
      }

    };
    $guard = new class($this->seen) implements ReplayGuardInterface {

      public function __construct(private array &$seen) {}

      /**
       * {@inheritdoc}
       */
      public function claim(string $sender, string $message_id, int $expires): bool {
        if (isset($this->seen[$sender . $message_id])) {
          return FALSE;
        }
        return $this->seen[$sender . $message_id] = TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function prune(int $now): int {
        return 0;
      }

    };
    return new JwtAuthenticator($keys, $identity, $resolver, $guard, $time, new PhpUuid());
  }

  /**
   * A token the replica issued for the origin is accepted, once.
   */
  public function testValidToken(): void {
    $token = $this->site(self::REPLICA)->issue(self::ORIGIN, 'sync:read');
    $origin = $this->site(self::ORIGIN);
    $this->assertSame(self::REPLICA, $origin->authenticate($token, 'sync:read'));

    $this->expectExceptionObject(new AuthenticationException('replayed', 'The token was already used.'));
    $origin->authenticate($token, 'sync:read');
  }

  /**
   * Each kind of bad token is refused with its own reason.
   *
   * @dataProvider providerRefused
   */
  #[DataProvider('providerRefused')]
  public function testRefused(string $case, string $reason): void {
    $replica = $this->site(self::REPLICA);
    $token = match ($case) {
      'wrong_audience' => $replica->issue('another-origin', 'sync:read'),
      default => $replica->issue(self::ORIGIN, 'sync:read'),
    };
    $scope = 'sync:read';
    switch ($case) {
      case 'expired':
        $this->now += JwtAuthenticator::LIFETIME + JwtAuthenticator::LEEWAY + 1;
        break;

      case 'wrong_scope':
        $scope = 'sync:write';
        break;

      case 'unknown_key':
        $this->known = [];
        break;

      case 'tampered':
        [$header, $payload, $signature] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), TRUE);
        $claims['scope'] = 'sync:admin';
        $token = $header . '.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.' . $signature;
        $scope = 'sync:admin';
        break;

      case 'too_long':
        $now = (new \DateTimeImmutable())->setTimestamp($this->now);
        $builder = Builder::new(new JoseEncoder(), ChainedFormatter::default())
          ->issuedBy(self::REPLICA)
          ->permittedFor(self::ORIGIN)
          ->identifiedBy('long')
          ->issuedAt($now)
          ->canOnlyBeUsedAfter($now)
          ->expiresAt($now->modify('+1 hour'))
          ->withClaim('scope', 'sync:read')
          ->withHeader('kid', $this->key->id());
        $token = $this->key->withSecret(fn ($secret) => $builder->getToken(new Eddsa(), InMemory::plainText($secret))->toString());
        break;

      case 'garbage':
        $token = 'not.a.token';
        break;
    }
    try {
      $this->site(self::ORIGIN)->authenticate($token, $scope);
      $this->fail('The token was accepted.');
    }
    catch (AuthenticationException $e) {
      $this->assertSame($reason, $e->reason);
    }
  }

  /**
   * Data provider for testRefused().
   */
  public static function providerRefused(): array {
    return [
      'expired' => ['expired', 'expired'],
      'wrong audience' => ['wrong_audience', 'wrong_audience'],
      'wrong scope' => ['wrong_scope', 'wrong_scope'],
      'unknown or retired key' => ['unknown_key', 'unknown_key'],
      'tampered claims' => ['tampered', 'bad_signature'],
      'lifetime longer than allowed' => ['too_long', 'lifetime'],
      'not a token' => ['garbage', 'malformed'],
    ];
  }

}
