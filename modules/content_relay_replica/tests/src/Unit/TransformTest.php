<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Unit;

use Drupal\content_relay_replica\Mapping\MappingException;
use Drupal\content_relay_replica\Mapping\TransformContext;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\Callback;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\Concat;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\DateFormat;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\DefaultValue;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\StaticValue;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\TaxonomyLookup;
use Drupal\content_relay_replica\Plugin\ContentRelay\Transform\TextFormat;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the transforms.
 *
 * @group content_relay
 */
#[Group('content_relay')]
final class TransformTest extends UnitTestCase {

  /**
   * A context reading other fields from a small snapshot.
   */
  private function context(): TransformContext {
    $snapshot = [
      'default_langcode' => 'en',
      'shared' => ['field_first' => [['value' => 'Ada']]],
      'translations' => ['en' => ['field_last' => [['value' => 'Lovelace']], 'field_middle' => []]],
    ];
    $reader = function (string $path, string $langcode) use ($snapshot): array {
      [$field, $property] = explode('.', $path, 2) + [1 => NULL];
      $items = $snapshot['shared'][$field] ?? $snapshot['translations'][$langcode][$field] ?? [];
      return $property === NULL ? $items : array_column($items, $property);
    };
    return new TransformContext($snapshot, 'en', 'field_first', 'title', $reader);
  }

  /**
   * Static and default values.
   */
  public function testStaticAndDefault(): void {
    $this->assertSame(['Fixed'], (new StaticValue(['value' => 'Fixed'], 'static_value', []))->transform(['anything'], $this->context()));
    $default = new DefaultValue(['value' => 'Untitled'], 'default_value', []);
    $this->assertSame(['Untitled'], $default->transform([], $this->context()));
    $this->assertSame(['Untitled'], $default->transform(['', NULL], $this->context()));
    $this->assertSame(['Given'], $default->transform(['Given'], $this->context()));
    $this->assertNotEmpty((new DefaultValue([], 'default_value', []))->validateConfiguration());
  }

  /**
   * Text formats are renamed, others kept or defaulted.
   */
  public function testTextFormat(): void {
    $items = [['value' => '<p>a</p>', 'format' => 'basic_html'], ['value' => 'b', 'format' => 'markdown'], 'plain'];
    $mapped = (new TextFormat(['map' => ['basic_html' => 'restricted']], 'text_format', []))->transform($items, $this->context());
    $this->assertSame([
      ['value' => '<p>a</p>', 'format' => 'restricted'],
      ['value' => 'b', 'format' => 'markdown'],
      'plain',
    ], $mapped);
    $defaulted = (new TextFormat(['map' => ['basic_html' => 'restricted'], 'default' => 'plain_text'], 'text_format', []))->transform($items, $this->context());
    $this->assertSame('plain_text', $defaulted[1]['format']);
    // The format property mapped on its own.
    $this->assertSame(['restricted', 'markdown'], (new TextFormat(['map' => ['basic_html' => 'restricted']], 'text_format', []))->transform([
      'basic_html',
      'markdown',
    ], $this->context()));
  }

  /**
   * Dates convert between formats; a value that is not a date refuses.
   */
  public function testDateFormat(): void {
    $plugin = new DateFormat(['from_format' => 'U', 'to_format' => 'Y-m-d\TH:i:s'], 'date_format', []);
    $this->assertSame(['2023-11-14T22:13:20', NULL], $plugin->transform([1700000000, NULL], $this->context()));
    $back = new DateFormat(['from_format' => 'Y-m-d', 'to_format' => 'U'], 'date_format', []);
    $this->assertSame([['value' => 1700006400]], $back->transform([['value' => '2023-11-15']], $this->context()));
    $berlin = new DateFormat(['from_format' => 'Y-m-d H:i', 'to_format' => 'U', 'timezone' => 'Europe/Berlin'], 'date_format', []);
    $this->assertSame([1699999200], $berlin->transform(['2023-11-14 23:00'], $this->context()));
    $this->expectException(MappingException::class);
    $plugin->transform(['next tuesday'], $this->context());
  }

  /**
   * Concatenation joins this field with others, skipping empty parts.
   */
  public function testConcat(): void {
    $plugin = new Concat(['sources' => ['field_middle.value', 'field_last.value'], 'delimiter' => ' '], 'concat', []);
    $this->assertSame(['Ada Lovelace'], $plugin->transform([['value' => 'Ada']], $this->context()));
    $this->assertSame([], (new Concat([], 'concat', []))->transform([''], $this->context()));
  }

  /**
   * Callbacks run on each value; a missing callable is a configuration error.
   */
  public function testCallback(): void {
    $plugin = new Callback(['callable' => 'strtoupper'], 'callback', []);
    $this->assertSame(['ADA', ['value' => 'LOVELACE']], $plugin->transform(['ada', ['value' => 'lovelace']], $this->context()));
    $this->assertSame([], $plugin->validateConfiguration());
    $this->assertNotEmpty((new Callback(['callable' => 'no_such_function_here'], 'callback', []))->validateConfiguration());
  }

  /**
   * Terms are found by UUID, in a vocabulary if one is set.
   *
   * A missing term is left out, or refuses the item when so configured.
   */
  public function testTaxonomyLookup(): void {
    $terms = [];
    foreach (['uuid-science' => [7, 'tags'], 'uuid-art' => [9, 'tags'], 'uuid-red' => [11, 'colors']] as $uuid => [
      $id,
      $vid,
    ]) {
      $term = $this->createMock(TermInterface::class);
      $term->method('uuid')->willReturn($uuid);
      $term->method('id')->willReturn($id);
      $terms[] = [$term, $vid];
    }
    /** @var \ArrayObject<int, array> $queries */
    $queries = new \ArrayObject();
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(function (array $properties) use ($terms, $queries) {
      $queries[] = $properties;
      $found = [];
      foreach ($terms as [$term, $vid]) {
        if (in_array($term->uuid(), $properties['uuid'], TRUE) && (!isset($properties['vid']) || $properties['vid'] === $vid)) {
          $found[$term->id()] = $term;
        }
      }
      return $found;
    });
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('taxonomy_term')->willReturn($storage);
    $lookup = fn (array $configuration) => new TaxonomyLookup($configuration, 'taxonomy_lookup', [], $manager);

    // Reference items and bare UUIDs, in the order given; empty ones dropped.
    $values = [['target_uuid' => 'uuid-art'], 'uuid-science', ['target_uuid' => ''], NULL, 'uuid-red'];
    $this->assertSame([['target_id' => 9], ['target_id' => 7], ['target_id' => 11]], $lookup([])->transform($values, $this->context()));
    $this->assertSame([['uuid' => ['uuid-art', 'uuid-science', 'uuid-red']]], $queries->getArrayCopy());

    // Limited to a vocabulary, a term of another is missing: skipped...
    $this->assertSame([['target_id' => 9], ['target_id' => 7]], $lookup(['vocabulary' => 'tags'])->transform($values, $this->context()));
    $this->assertSame('tags', $queries[1]['vid']);
    $this->assertSame([], $lookup([])->transform(['uuid-gone'], $this->context()));
    $this->assertSame([], $lookup([])->transform([], $this->context()));
    $this->assertCount(3, $queries, 'Nothing to look up makes no query.');

    // ...or refused.
    try {
      $lookup(['vocabulary' => 'tags', 'missing' => 'reject'])->transform($values, $this->context());
      $this->fail('A missing term was not refused.');
    }
    catch (MappingException $e) {
      $this->assertSame('Term uuid-red for title is not on this site.', $e->getMessage());
    }

    $this->assertSame([], $lookup(['missing' => 'reject'])->validateConfiguration());
    $this->assertNotEmpty($lookup(['missing' => 'ignore'])->validateConfiguration());
  }

}
