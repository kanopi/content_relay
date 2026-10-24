<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests key material handling.
 *
 * @group content_relay
 * @coversDefaultClass \Drupal\content_relay\Key\KeyPair
 */
#[Group('content_relay')]
#[CoversClass(KeyPair::class)]
final class KeyPairTest extends UnitTestCase {

  /**
   * Stored material reads back as the same key, with the same ID.
   */
  public function testMaterialRoundTrip(): void {
    foreach (KeyPurpose::cases() as $purpose) {
      [$pair, $material] = KeyPair::generate($purpose);
      $read = KeyPair::fromMaterial($purpose, $material . "\n");
      $this->assertSame($pair->publicKey, $read->publicKey);
      $this->assertSame($pair->id(), $read->id());
      $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $pair->id());
    }
  }

  /**
   * A key for one purpose is refused for the other.
   */
  public function testPurposeIsEnforced(): void {
    [, $material] = KeyPair::generate(KeyPurpose::Encryption);
    $this->expectException(\InvalidArgumentException::class);
    KeyPair::fromMaterial(KeyPurpose::Signing, $material);
  }

  /**
   * Malformed material is refused.
   */
  public function testMalformedMaterial(): void {
    $this->expectException(\InvalidArgumentException::class);
    KeyPair::fromMaterial(KeyPurpose::Signing, KeyPurpose::Signing->materialPrefix() . base64_encode('short'));
  }

  /**
   * The secret never shows up in debug output or serialization.
   */
  public function testSecretDoesNotLeak(): void {
    [$pair, $material] = KeyPair::generate(KeyPurpose::Signing);
    $secret = base64_decode(substr($material, strlen(KeyPurpose::Signing->materialPrefix())));
    ob_start();
    var_dump($pair);
    $dump = (string) ob_get_clean();
    $this->assertStringNotContainsString($secret, $dump);
    $this->assertStringNotContainsString(base64_encode($secret), print_r($pair, TRUE));

    $this->expectException(\LogicException::class);
    serialize($pair);
  }

  /**
   * Signatures verify against the public key.
   */
  public function testSigning(): void {
    [$pair] = KeyPair::generate(KeyPurpose::Signing);
    $this->assertTrue(sodium_crypto_sign_verify_detached($pair->sign('bytes'), 'bytes', $pair->publicKey));

    [$box] = KeyPair::generate(KeyPurpose::Encryption);
    $this->expectException(\LogicException::class);
    $box->sign('bytes');
  }

}
