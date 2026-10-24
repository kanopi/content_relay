<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\content_relay\Hash\CanonicalJson;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the canonical JSON both hashing and signing depend on.
 *
 * @group content_relay
 * @coversDefaultClass \Drupal\content_relay\Hash\CanonicalJson
 */
#[Group('content_relay')]
#[CoversClass(CanonicalJson::class)]
final class CanonicalJsonTest extends UnitTestCase {

  /**
   * Map key order never matters, at any depth; list order always does.
   */
  public function testMapsAreSortedAndListsAreNot(): void {
    $one = ['b' => 1, 'a' => ['z' => TRUE, 'y' => [3, 1, 2]]];
    $two = ['a' => ['y' => [3, 1, 2], 'z' => TRUE], 'b' => 1];
    $this->assertSame(CanonicalJson::encode($one), CanonicalJson::encode($two));
    $this->assertSame('{"a":{"y":[3,1,2],"z":true},"b":1}', CanonicalJson::encode($one));
    $this->assertNotSame(CanonicalJson::encode([1, 2]), CanonicalJson::encode([2, 1]));
  }

  /**
   * Numeric-looking keys sort as strings, the same way on every site.
   */
  public function testNumericKeysSortAsStrings(): void {
    $this->assertSame('{"10":"b","9":"a","x":"c"}', CanonicalJson::encode(['x' => 'c', '9' => 'a', '10' => 'b']));
  }

  /**
   * Text is hashed as the bytes stored: no escaping, trimming or folding.
   */
  public function testUnicodeIsWrittenAsIs(): void {
    $text = " 中文 😀 שלום /path ";
    $this->assertSame('"' . $text . '"', CanonicalJson::encode($text));
    // Composed and decomposed é look the same and are different bytes. They
    // must stay different; normalizing them on one site and not the other is
    // a permanent false mismatch.
    $this->assertNotSame(CanonicalJson::encode("\u{00E9}"), CanonicalJson::encode("e\u{0301}"));
  }

  /**
   * Types are kept apart.
   */
  public function testTypesAreDistinct(): void {
    $encoded = array_map(CanonicalJson::encode(...), [1, 1.0, '1', TRUE, NULL]);
    $this->assertSame(['1', '1.0', '"1"', 'true', 'null'], $encoded);
  }

  /**
   * Encoding survives a decode with associative arrays unchanged.
   *
   * The receiving site hashes what it decoded, not what the sender built.
   */
  public function testRoundTripThroughDecode(): void {
    $value = ['b' => [], 'a' => [['x' => 1.5, 'y' => 'ü']], 'c' => ['k' => NULL]];
    $encoded = CanonicalJson::encode($value);
    $this->assertSame($encoded, CanonicalJson::encode(json_decode($encoded, TRUE)));
  }

  /**
   * Invalid UTF-8 is refused rather than repaired.
   */
  public function testInvalidUtf8IsRefused(): void {
    $this->expectException(\JsonException::class);
    CanonicalJson::encode(["\xB1\x31"]);
  }

  /**
   * Objects are refused: their encoding depends on a class.
   */
  public function testObjectsAreRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    CanonicalJson::encode(['a' => new \stdClass()]);
  }

}
