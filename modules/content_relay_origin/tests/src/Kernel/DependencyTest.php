<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that dependencies reach a replica before what references them.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class DependencyTest extends OriginTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'field_related',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_related'])->save();
  }

  /**
   * Saves an article referencing others.
   */
  private function article(string $title, array $related = [], int $uid = 0): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'uid' => $uid, 'field_related' => $related]);
    $node->save();
    return $node;
  }

  /**
   * Titles of the events the replica received, in order.
   */
  private function received(): array {
    return array_map(fn (array $event) => $event['snapshot']['shared']['title'][0]['value'] ?? $event['operation'], $this->replica->events);
  }

  /**
   * Content that existed before pairing is sent first when referenced.
   */
  public function testExistingDependencyIsSentFirst(): void {
    $old = $this->article('Old');
    $this->pairAndAcknowledge();
    $new = $this->article('New', [$old]);
    $this->deliver();
    $this->assertSame(['Old', 'New'], $this->received());
    $this->assertSame([['entity_type' => 'node', 'uuid' => $old->uuid()]], $this->replica->events[1]['dependencies']);

    // Known now: not sent again.
    $new->setTitle('Newer')->save();
    $this->deliver();
    $this->assertSame(['Old', 'New', 'Newer'], $this->received());
    $this->assertTrue($this->container->get('content_relay_origin.replica_items')->isKnown($this->replica->siteId, 'node', $old->uuid()));
  }

  /**
   * Items referencing each other are each sent once, with no loop.
   */
  public function testCircularReferences(): void {
    $one = $this->article('One');
    $two = $this->article('Two', [$one]);
    $one->set('field_related', [$two])->save();
    $this->pairAndAcknowledge();

    $one->setTitle('One again')->save();
    $this->deliver();
    $this->assertSame(['Two', 'One again'], $this->received());
  }

  /**
   * References outside the contract are announced as external, not sent.
   */
  public function testExternalReferences(): void {
    $author = User::create(['name' => 'author', 'status' => 1]);
    $author->save();
    $page = Node::create(['type' => 'page', 'title' => 'A page', 'uid' => 0]);
    $page->save();
    $this->pairAndAcknowledge();

    $this->article('Referencing', [$page], (int) $author->id());
    $this->deliver();
    $this->assertSame(['Referencing'], $this->received(), 'Neither the page nor the user was sent.');
    $event = $this->replica->events[0];
    $this->assertSame([], $event['dependencies']);
    $external = array_map(fn ($r) => $r['entity_type'] . ':' . $r['uuid'], $event['external_references']);
    sort($external);
    $expected = ['node:' . $page->uuid(), 'user:' . $author->uuid()];
    sort($expected);
    $this->assertSame($expected, $external);
  }

  /**
   * A dependency that cannot be delivered holds back the item behind it.
   */
  public function testFailedDependencyHoldsTheItem(): void {
    $old = $this->article('Old');
    $this->pairAndAcknowledge();
    $this->article('New', [$old]);
    $this->replica->eventResponses = [[503, ['result' => 'busy']]];
    $this->deliver();
    $this->assertSame(['Old'], $this->received());

    // Until its retry is due, the dependency is not sent again, and the
    // item waits behind it.
    $this->deliver();
    $this->assertSame(['Old'], $this->received());
    $this->assertSame(1, (int) $this->container->get('content_relay_origin.outbox')->pendingRowFor($this->replica->siteId, 'node', $old->uuid())['attempts']);

    $this->container->get('database')->update('content_relay_outbox')->fields(['next_attempt' => 0])->execute();
    $this->deliver();
    $this->assertSame(['Old', 'Old', 'New'], $this->received());
  }

}
