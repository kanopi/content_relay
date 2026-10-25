<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Crypto\MessageVerifier;
use Drupal\content_relay\Crypto\PeerPolicy;
use Drupal\content_relay\Crypto\PeerPolicyInterface;
use Drupal\content_relay\Crypto\PublicKeyResolverInterface;
use Drupal\content_relay\Crypto\ReplayGuardInterface;
use Drupal\content_relay\Crypto\SignedEnvelope;
use Drupal\content_relay\Crypto\VerificationException;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\content_relay\SiteIdentity;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests signing and every reason a message is refused.
 *
 * @group content_relay
 * @coversDefaultClass \Drupal\content_relay\Crypto\MessageVerifier
 */
#[Group('content_relay')]
#[CoversClass(MessageVerifier::class)]
#[CoversClass(MessageSigner::class)]
#[CoversClass(SignedEnvelope::class)]
final class MessageSignatureTest extends UnitTestCase {

  private const ORIGIN = 'origin-site';
  private const REPLICA = 'replica-site';
  private const NOW = 1700000000;

  /**
   * The origin's signing key.
   */
  private KeyPair $key;

  /**
   * Public keys the replica knows, by key ID.
   *
   * @var array<string, string>
   */
  private array $known = [];

  /**
   * Message IDs the replica has seen.
   *
   * @var array<string, true>
   */
  private array $seen = [];

  /**
   * The replica's clock.
   */
  private int $now = self::NOW;

  /**
   * The replica's encryption key.
   */
  private KeyPair $replicaEncryption;

  /**
   * Whether the replica refuses unencrypted messages by its own setting.
   */
  private bool $require = FALSE;

  /**
   * What the replica's contract says about unencrypted messages.
   */
  private ?bool $contractAllowsUnencrypted = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    [$this->key] = KeyPair::generate(KeyPurpose::Signing);
    $this->known[$this->key->id()] = $this->key->publicKey;
    [$this->replicaEncryption] = KeyPair::generate(KeyPurpose::Encryption);
  }

  /**
   * Signs a message from the origin to the replica, sealed or not.
   */
  private function sign(array $body = ['event' => 'x'], string $recipient = self::REPLICA, ?KeyPair $key = NULL, int $timestamp = self::NOW, EncryptionMode $mode = EncryptionMode::None, ?EncryptionMode $signed_mode = NULL): array {
    $message = new Message((new PhpUuid())->generate(), 'event', self::ORIGIN, $recipient, $timestamp, $signed_mode ?? $mode, $body, 1);
    $signer = new MessageSigner($this->createMock(KeyStore::class), $this->createMock(SiteIdentity::class), $this->createMock(TimeInterface::class), new PhpUuid(), new PeerPolicy($this->getConfigFactoryStub([])));
    $envelope = $signer->signMessage($message, $key ?? $this->key);
    if ($mode === EncryptionMode::Sealed) {
      return MessageSigner::seal($envelope, $this->replicaEncryption->id(), $this->replicaEncryption->publicKey)->toArray();
    }
    // Sent in the clear, whatever the message says.
    return [
      'v' => SignedEnvelope::SCHEMA_VERSION,
      'sender' => $envelope->sender,
      'signing_key_id' => $envelope->signingKeyId,
      'encryption' => EncryptionMode::None->value,
      'message' => $envelope->message,
      'signature' => $envelope->signature,
    ];
  }

  /**
   * The replica's verifier.
   */
  private function verifier(): MessageVerifier {
    $keys = new class($this->known) implements PublicKeyResolverInterface {

      public function __construct(private array $known) {}

      /**
       * {@inheritdoc}
       */
      public function getPublicKey(string $site_id, KeyPurpose $purpose, string $key_id): ?string {
        return $site_id === 'origin-site' && $purpose === KeyPurpose::Signing ? ($this->known[$key_id] ?? NULL) : NULL;
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
    $identity = $this->createMock(SiteIdentity::class);
    $identity->method('getSiteId')->willReturn(self::REPLICA);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $this->now);
    $config = $this->getConfigFactoryStub([
      'content_relay.settings' => [
        'signature' => ['clock_window' => 300],
        'encryption' => ['require' => $this->require],
      ],
    ]);
    $key_store = $this->createMock(KeyStore::class);
    $key_store->method('get')->willReturnCallback(fn (KeyPurpose $purpose, string $id) => $purpose === KeyPurpose::Encryption && $id === $this->replicaEncryption->id() ? $this->replicaEncryption : NULL);
    $policy = new PeerPolicy($config);
    $policy->addPolicy(new class($this->contractAllowsUnencrypted) implements PeerPolicyInterface {

      public function __construct(private ?bool $allows) {}

      /**
       * {@inheritdoc}
       */
      public function sendMode(string $peer): ?EncryptionMode {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function peerEncryptionKey(string $peer): ?array {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function trustedSigningKeyIds(string $peer): ?array {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function allowsUnencrypted(string $peer): ?bool {
        return $this->allows;
      }

    });
    return new MessageVerifier($keys, $guard, $identity, $config, $time, $key_store, $policy);
  }

  /**
   * A valid message verifies, through a JSON round trip.
   */
  public function testValidMessage(): void {
    $envelope = json_decode(json_encode($this->sign(['nested' => ['b' => 2, 'a' => '中文']])), TRUE);
    $message = $this->verifier()->verify($envelope);
    $this->assertSame(self::ORIGIN, $message->sender);
    $this->assertSame(['nested' => ['b' => 2, 'a' => '中文']], $message->body);
  }

  /**
   * Each kind of tampering or misuse is refused with its own reason.
   *
   * @dataProvider providerRefused
   */
  #[DataProvider('providerRefused')]
  public function testRefused(string $case, string $reason): void {
    $envelope = $this->sign();
    switch ($case) {
      case 'body':
        $envelope['message']['body']['event'] = 'y';
        break;

      case 'sequence':
        $envelope['message']['sequence'] = 2;
        break;

      case 'signed_mode':
        $envelope['message']['encryption'] = 'sealed';
        break;

      case 'envelope_sender':
        $envelope['sender'] = 'someone-else';
        break;

      case 'unknown_key':
        $envelope['signing_key_id'] = '0000000000000000';
        break;

      case 'other_key':
        [$other] = KeyPair::generate(KeyPurpose::Signing);
        $envelope = ['signing_key_id' => $envelope['signing_key_id']] + $this->sign(key: $other);
        break;

      case 'garbage_signature':
        $envelope['signature'] = 'not base64!';
        break;

      case 'missing_signature':
        unset($envelope['signature']);
        break;

      case 'envelope_version':
        $envelope['v'] = 2;
        break;

      case 'sealed_to_unknown_key':
        $envelope = $this->sign(mode: EncryptionMode::Sealed);
        $envelope['encryption_key_id'] = '0000000000000000';
        break;

      case 'sealed_garbage':
        $envelope = $this->sign(mode: EncryptionMode::Sealed);
        $envelope['ciphertext'] = base64_encode(random_bytes(200));
        break;

      case 'sealed_tampered':
        $envelope = $this->sign(mode: EncryptionMode::Sealed);
        $raw = base64_decode($envelope['ciphertext']);
        $raw[60] = chr(ord($raw[60]) ^ 1);
        $envelope['ciphertext'] = base64_encode($raw);
        break;

      case 'unsealed_in_transit':
        // Signed as sealed, then sent in the clear by someone who opened it.
        $envelope = $this->sign(signed_mode: EncryptionMode::Sealed);
        break;

      case 'sealed_after_signing_plain':
        // Signed as unencrypted, then sealed: the mode does not match.
        $envelope = $this->sign(mode: EncryptionMode::Sealed, signed_mode: EncryptionMode::None);
        break;

      case 'envelope_mode':
        $envelope['encryption'] = 'sealed';
        break;

      case 'recipient':
        $envelope = $this->sign(recipient: 'replica-two');
        break;

      case 'old':
        $envelope = $this->sign(timestamp: self::NOW - 301);
        break;

      case 'future':
        $envelope = $this->sign(timestamp: self::NOW + 301);
        break;
    }
    try {
      $this->verifier()->verify($envelope);
      $this->fail('The message was accepted.');
    }
    catch (VerificationException $e) {
      $this->assertSame($reason, $e->reason);
    }
  }

  /**
   * Data provider for testRefused().
   */
  public static function providerRefused(): array {
    return [
      'tampered body' => ['body', VerificationException::BAD_SIGNATURE],
      'tampered signed header' => ['sequence', VerificationException::BAD_SIGNATURE],
      'tampered signed encryption mode' => ['signed_mode', VerificationException::BAD_SIGNATURE],
      'tampered envelope sender' => ['envelope_sender', VerificationException::UNKNOWN_KEY],
      'unknown key' => ['unknown_key', VerificationException::UNKNOWN_KEY],
      'signed with another key' => ['other_key', VerificationException::BAD_SIGNATURE],
      'garbage signature' => ['garbage_signature', VerificationException::BAD_SIGNATURE],
      'missing signature' => ['missing_signature', VerificationException::MALFORMED],
      'unknown envelope version' => ['envelope_version', VerificationException::MALFORMED],
      'sealed to a key this site does not have' => ['sealed_to_unknown_key', VerificationException::UNKNOWN_KEY],
      'sealed garbage' => ['sealed_garbage', VerificationException::DECRYPTION_FAILED],
      'tampered ciphertext' => ['sealed_tampered', VerificationException::DECRYPTION_FAILED],
      'unsealed in transit' => ['unsealed_in_transit', VerificationException::HEADER_MISMATCH],
      'sealed after signing unencrypted' => ['sealed_after_signing_plain', VerificationException::HEADER_MISMATCH],
      'tampered envelope encryption mode' => ['envelope_mode', VerificationException::MALFORMED],
      'addressed to another replica' => ['recipient', VerificationException::WRONG_RECIPIENT],
      'too old' => ['old', VerificationException::STALE],
      'from the future' => ['future', VerificationException::STALE],
    ];
  }

  /**
   * The same message is accepted once.
   */
  public function testReplayIsRefused(): void {
    $envelope = $this->sign();
    $verifier = $this->verifier();
    $verifier->verify($envelope);
    $this->expectExceptionObject(new VerificationException(VerificationException::REPLAYED, 'The message was already received.'));
    $verifier->verify($envelope);
  }

  /**
   * A forged message does not use up the ID of a later genuine one.
   */
  public function testForgeryDoesNotClaimTheMessageId(): void {
    $envelope = $this->sign();
    $forged = $envelope;
    $forged['message']['body'] = ['evil' => TRUE];
    $verifier = $this->verifier();
    try {
      $verifier->verify($forged);
    }
    catch (VerificationException) {
    }
    $this->assertSame(self::ORIGIN, $verifier->verify($envelope)->sender);
  }

  /**
   * A retired key, no longer resolvable, is refused.
   */
  public function testRetiredKeyIsRefused(): void {
    $envelope = $this->sign();
    $this->known = [];
    $this->expectException(VerificationException::class);
    $this->verifier()->verify($envelope);
  }

  /**
   * Two keys work side by side during a rotation.
   */
  public function testRotationOverlap(): void {
    [$new] = KeyPair::generate(KeyPurpose::Signing);
    $this->known[$new->id()] = $new->publicKey;
    $verifier = $this->verifier();
    $this->assertSame(self::ORIGIN, $verifier->verify($this->sign())->sender);
    $this->assertSame(self::ORIGIN, $verifier->verify($this->sign(key: $new))->sender);
  }

  /**
   * A sealed message verifies, and nothing of it can be read in transit.
   */
  public function testSealedMessage(): void {
    $envelope = json_decode(json_encode($this->sign(['secret' => 'the quarterly numbers'], mode: EncryptionMode::Sealed)), TRUE);
    $this->assertArrayNotHasKey('message', $envelope);
    $this->assertArrayNotHasKey('signature', $envelope);
    $this->assertStringNotContainsString('quarterly', json_encode($envelope));
    $this->assertStringNotContainsString('quarterly', (string) base64_decode($envelope['ciphertext']));
    $message = $this->verifier()->verify($envelope);
    $this->assertSame(['secret' => 'the quarterly numbers'], $message->body);
    $this->assertSame(EncryptionMode::Sealed, $message->encryption);
  }

  /**
   * Unencrypted messages are refused when this site or the contract says so.
   */
  public function testDowngradeIsRefused(): void {
    $this->contractAllowsUnencrypted = FALSE;
    try {
      $this->verifier()->verify($this->sign());
      $this->fail('An unencrypted message was accepted.');
    }
    catch (VerificationException $e) {
      $this->assertSame(VerificationException::ENCRYPTION_REQUIRED, $e->reason);
    }
    $this->assertSame(self::ORIGIN, $this->verifier()->verify($this->sign(mode: EncryptionMode::Sealed))->sender);

    // This site's own setting wins over a contract that would allow it.
    $this->contractAllowsUnencrypted = TRUE;
    $this->require = TRUE;
    try {
      $this->verifier()->verify($this->sign());
      $this->fail('An unencrypted message was accepted.');
    }
    catch (VerificationException $e) {
      $this->assertSame(VerificationException::ENCRYPTION_REQUIRED, $e->reason);
    }
  }

}
