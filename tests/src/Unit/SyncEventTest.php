<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Unit;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\ProcessingMode;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Exception\InvalidEventException;
use Drupal\content_relay\Hash\RevisionHasher;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the versioned event schema.
 *
 * @group content_relay
 * @coversDefaultClass \Drupal\content_relay\Event\SyncEvent
 */
#[Group('content_relay')]
#[CoversClass(SyncEvent::class)]
final class SyncEventTest extends UnitTestCase {

  private const UUID = '6f0c2a43-1b8e-4e5c-9a52-0d4c5b1f2e3a';
  private const EVENT = '0e8f6d1c-2b3a-4c5d-9e8f-7a6b5c4d3e2f';

  /**
   * A valid update event as an array.
   */
  private function eventData(): array {
    $snapshot = [
      'entity_type' => 'node',
      'bundle' => 'article',
      'uuid' => self::UUID,
      'default_langcode' => 'en',
      'shared' => [],
      'translations' => ['en' => ['title' => [['value' => 'Hello']]], 'fr' => []],
      'metadata' => [],
    ];
    return [
      'v' => 1,
      'event_id' => self::EVENT,
      'sequence' => 7,
      'previous_sequence' => 5,
      'operation' => 'update',
      'entity_type' => 'node',
      'bundle' => 'article',
      'entity_uuid' => self::UUID,
      'source_revision' => 42,
      'revisionable' => TRUE,
      'source_changed' => 1700000000,
      'default_langcode' => 'en',
      'langcodes' => ['fr', 'en'],
      'hash' => (new RevisionHasher())->hash($snapshot)->toArray(),
      'contract_version' => 1,
      'created' => 1700000001,
      'dependencies' => [['entity_type' => 'taxonomy_term', 'uuid' => self::EVENT]],
      'files' => [],
      'process' => 'immediate',
      'snapshot' => $snapshot,
    ];
  }

  /**
   * An event survives its array form, including through JSON.
   */
  public function testRoundTrip(): void {
    $event = SyncEvent::fromArray($this->eventData());
    $this->assertSame(Operation::Update, $event->operation);
    $this->assertSame(ProcessingMode::Immediate, $event->process);
    $decoded = json_decode(json_encode($event->toArray()), TRUE);
    $this->assertEquals($event, SyncEvent::fromArray($decoded));
  }

  /**
   * Deletes, detaches and attaches carry no snapshot.
   */
  public function testDeleteCarriesNoSnapshot(): void {
    $data = ['operation' => 'delete', 'snapshot' => NULL, 'hash' => NULL, 'langcodes' => []] + $this->eventData();
    $this->assertSame(Operation::Delete, SyncEvent::fromArray($data)->operation);

    $this->expectException(InvalidEventException::class);
    SyncEvent::fromArray(['operation' => 'delete'] + $this->eventData());
  }

  /**
   * Invalid events are refused.
   *
   * @dataProvider providerInvalid
   */
  #[DataProvider('providerInvalid')]
  public function testInvalid(array $override, ?\Closure $mutate = NULL): void {
    $data = $override + $this->eventData();
    if ($mutate) {
      $data = $mutate($data);
    }
    $this->expectException(InvalidEventException::class);
    SyncEvent::fromArray($data);
  }

  /**
   * Data provider for testInvalid().
   */
  public static function providerInvalid(): array {
    return [
      'unknown schema version' => [['v' => 2]],
      'missing version' => [[], fn ($d) => array_diff_key($d, ['v' => 1])],
      'unknown operation' => [['operation' => 'merge']],
      'bad event ID' => [['event_id' => 'nope']],
      'sequence zero' => [['sequence' => 0]],
      'previous sequence after this one' => [['previous_sequence' => 7]],
      'string sequence' => [['sequence' => '7']],
      'no snapshot on update' => [['snapshot' => NULL]],
      'no hash on update' => [['hash' => NULL]],
      'malformed hash' => [['hash' => ['algorithm' => 1]]],
      'uuid differs from snapshot' => [['entity_uuid' => self::EVENT]],
      'langcodes differ from snapshot' => [['langcodes' => ['en']]],
      'unknown processing mode' => [['process' => 'later']],
      'malformed dependency' => [['dependencies' => [['uuid' => self::UUID]]]],
    ];
  }

}
