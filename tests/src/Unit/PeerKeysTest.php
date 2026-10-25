<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\PeerKeys;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests checking and choosing a peer's public keys.
 *
 * @group content_relay
 */
#[Group('content_relay')]
final class PeerKeysTest extends UnitTestCase {

  /**
   * A key set as it arrives in JSON.
   */
  private static function received(KeyPair ...$keys): array {
    $set = [];
    foreach ($keys as $i => $key) {
      $set[$key->id()] = ['public_key' => base64_encode($key->publicKey), 'status' => $i === 0 ? 'primary' : 'active'];
    }
    return json_decode(json_encode($set), TRUE);
  }

  /**
   * Valid keys pass; altered ones do not.
   */
  public function testCheck(): void {
    [$a] = KeyPair::generate(KeyPurpose::Encryption);
    [$b] = KeyPair::generate(KeyPurpose::Encryption);
    $this->assertSame([$a->id(), $b->id()], array_map('strval', array_keys(PeerKeys::check(self::received($a, $b), KeyPurpose::Encryption))));

    $wrong_id = self::received($a);
    $wrong_id['0000000000000000'] = reset($wrong_id);
    unset($wrong_id[$a->id()]);
    $this->expectException(\InvalidArgumentException::class);
    PeerKeys::check($wrong_id, KeyPurpose::Encryption);
  }

  /**
   * A key whose ID is all digits, an integer array key in PHP, is accepted.
   */
  public function testNumericKeyId(): void {
    for ($i = 0; $i < 200000; $i++) {
      [$key] = KeyPair::generate(KeyPurpose::Signing);
      if (ctype_digit($key->id())) {
        break;
      }
    }
    $this->assertTrue(ctype_digit($key->id()), 'Found a key with an all-digit ID.');
    $set = self::received($key);
    $this->assertIsInt(array_key_first($set));
    $this->assertArrayHasKey($key->id(), PeerKeys::check($set, KeyPurpose::Signing));
  }

  /**
   * The primary key is preferred, then any active one; retired never.
   */
  public function testPreferred(): void {
    [$a] = KeyPair::generate(KeyPurpose::Encryption);
    [$b] = KeyPair::generate(KeyPurpose::Encryption);
    $set = self::received($b, $a);
    $this->assertSame($b->id(), PeerKeys::preferred($set)['id']);
    $set[$b->id()]['status'] = 'retired';
    $this->assertSame(['id' => $a->id(), 'public_key' => $a->publicKey], PeerKeys::preferred($set));
    $this->assertNull(PeerKeys::preferred([]));
  }

}
