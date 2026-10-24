<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\content_relay\Hash\RevisionHashes;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests canonical revision hashing on snapshots.
 *
 * @group content_relay
 * @coversDefaultClass \Drupal\content_relay\Hash\RevisionHasher
 */
#[Group('content_relay')]
#[CoversClass(RevisionHasher::class)]
#[CoversClass(RevisionHashes::class)]
final class RevisionHasherTest extends UnitTestCase {

  /**
   * The hasher.
   */
  private RevisionHasher $hasher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->hasher = new RevisionHasher();
  }

  /**
   * A snapshot of an article in English and French with a paragraph.
   */
  private function snapshot(): array {
    return [
      'entity_type' => 'node',
      'bundle' => 'article',
      'uuid' => '6f0c2a43-1b8e-4e5c-9a52-0d4c5b1f2e3a',
      'default_langcode' => 'en',
      'shared' => [
        'field_tags' => [['target_type' => 'taxonomy_term', 'target_uuid' => 'aa2c8e1d-3a4b-4c5d-8e9f-0a1b2c3d4e5f']],
        'field_sections' => [[
          'entity' => [
            'entity_type' => 'paragraph',
            'bundle' => 'text',
            'uuid' => 'bb2c8e1d-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
            'default_langcode' => 'en',
            'shared' => [],
            'translations' => [
              'en' => ['field_text' => [['value' => 'Paragraph']]],
              'fr' => ['field_text' => [['value' => 'Paragraphe']]],
            ],
            'metadata' => ['shared' => [], 'translations' => []],
          ],
        ],
        ],
      ],
      'translations' => [
        'en' => ['title' => [['value' => 'Hello']], 'status' => [['value' => TRUE]]],
        'fr' => ['title' => [['value' => 'Bonjour']], 'status' => [['value' => TRUE]]],
      ],
      'metadata' => [
        'shared' => [],
        'translations' => ['en' => ['changed' => [['value' => 1700000000]]]],
      ],
    ];
  }

  /**
   * Recursively reverses the key order of every map.
   */
  private function shuffleKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    $value = array_map($this->shuffleKeys(...), $value);
    return array_is_list($value) ? $value : array_reverse($value, TRUE);
  }

  /**
   * The same snapshot hashes the same regardless of key order.
   */
  public function testDeterministic(): void {
    $one = $this->hasher->hash($this->snapshot());
    $two = $this->hasher->hash($this->shuffleKeys($this->snapshot()));
    $this->assertEquals($one, $two);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $one->entity);
    $this->assertSame(['en', 'fr'], array_keys($one->translations));
    $this->assertSame(RevisionHasher::CURRENT_VERSION, $one->algorithmVersion);
  }

  /**
   * Any synced value changes the hash; metadata does not.
   */
  public function testContentChangesTheHashAndMetadataDoesNot(): void {
    $base = $this->hasher->hash($this->snapshot());

    $changed = $this->snapshot();
    $changed['metadata']['translations']['en']['changed'][0]['value']++;
    $this->assertEquals($base, $this->hasher->hash($changed));

    $retitled = $this->snapshot();
    $retitled['translations']['en']['title'][0]['value'] = 'Hello!';
    $this->assertNotSame($base->entity, $this->hasher->hash($retitled)->entity);

    $retyped = $this->snapshot();
    $retyped['translations']['en']['status'][0]['value'] = 1;
    $this->assertNotSame($base->entity, $this->hasher->hash($retyped)->entity, 'TRUE and 1 are different values.');

    $reordered = $this->snapshot();
    $reordered['shared']['field_tags'][] = [
      'target_type' => 'taxonomy_term',
      'target_uuid' => 'cc2c8e1d-3a4b-4c5d-8e9f-0a1b2c3d4e5f',
    ];
    $reordered2 = $reordered;
    $reordered2['shared']['field_tags'] = array_reverse($reordered2['shared']['field_tags']);
    $this->assertNotSame($this->hasher->hash($reordered)->entity, $this->hasher->hash($reordered2)->entity, 'Delta order is content.');
  }

  /**
   * Changing one translation changes only that translation's hash.
   */
  public function testTranslationHashesAreIndependent(): void {
    $base = $this->hasher->hash($this->snapshot());

    $french = $this->snapshot();
    $french['translations']['fr']['title'][0]['value'] = 'Salut';
    $after = $this->hasher->hash($french);
    $this->assertNotSame($base->entity, $after->entity);
    $this->assertSame(['fr'], $base->differingTranslations($after));

    // A shared field belongs to every translation.
    $tagged = $this->snapshot();
    $tagged['shared']['field_tags'] = [];
    $this->assertSame(['en', 'fr'], $base->differingTranslations($this->hasher->hash($tagged)));

    // A language the replica does not receive is not compared.
    $this->assertSame([], $base->differingTranslations($after, ['en']));
  }

  /**
   * Embedded entities count, and in the parent translation's language.
   */
  public function testEmbeddedEntitiesCountPerLanguage(): void {
    $base = $this->hasher->hash($this->snapshot());
    $edited = $this->snapshot();
    $edited['shared']['field_sections'][0]['entity']['translations']['fr']['field_text'][0]['value'] = 'Autre';
    $after = $this->hasher->hash($edited);
    $this->assertNotSame($base->entity, $after->entity);
    $this->assertSame(['fr'], $base->differingTranslations($after));
  }

  /**
   * Local-only fields are left out, and nothing else is.
   */
  public function testLocalOnlyFieldsAreExcluded(): void {
    $base = $this->hasher->hash($this->snapshot(), ['field_tags']);
    $edited = $this->snapshot();
    $edited['shared']['field_tags'] = [];
    $this->assertEquals($base, $this->hasher->hash($edited, ['field_tags']));
    $this->assertNotEquals($base, $this->hasher->hash($this->snapshot()));
  }

  /**
   * CJK, emoji and right-to-left text hash identically after a JSON trip.
   */
  public function testMultilingualTextSurvivesTransport(): void {
    $snapshot = $this->snapshot();
    $snapshot['translations']['zh-hans'] = ['title' => [['value' => '你好，世界 🌏']]];
    $snapshot['translations']['ar'] = ['title' => [['value' => 'مرحبا بالعالم']]];
    $received = json_decode(json_encode($snapshot), TRUE);
    $this->assertEquals($this->hasher->hash($snapshot), $this->hasher->hash($received));
  }

  /**
   * A payload altered after hashing no longer matches.
   */
  public function testMatches(): void {
    $snapshot = $this->snapshot();
    $hashes = $this->hasher->hash($snapshot);
    $this->assertTrue($this->hasher->matches($snapshot, $hashes));

    $snapshot['translations']['en']['title'][0]['value'] = 'Tampered';
    $this->assertFalse($this->hasher->matches($snapshot, $hashes));
    $this->assertFalse($this->hasher->matches(['not' => 'a snapshot'], $hashes));
    $this->assertFalse($this->hasher->matches($this->snapshot(), new RevisionHashes(99, $hashes->entity, $hashes->translations)));
  }

  /**
   * Hashes of different algorithm versions are not compared.
   */
  public function testVersionsAreNotComparable(): void {
    $hashes = $this->hasher->hash($this->snapshot());
    $other = new RevisionHashes(2, $hashes->entity, $hashes->translations);
    $this->assertFalse($hashes->isComparableWith($other));
    $this->expectException(\LogicException::class);
    $hashes->differingTranslations($other);
  }

  /**
   * Unknown versions and malformed snapshots are refused.
   */
  public function testRefusals(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->hasher->hash($this->snapshot(), [], 99);
  }

  /**
   * Hashes survive their array form.
   */
  public function testArrayRoundTrip(): void {
    $hashes = $this->hasher->hash($this->snapshot());
    $this->assertEquals($hashes, RevisionHashes::fromArray(json_decode(json_encode($hashes->toArray()), TRUE)));
    $this->expectException(\InvalidArgumentException::class);
    RevisionHashes::fromArray(['algorithm' => 1, 'entity' => 'nope', 'translations' => []]);
  }

}
