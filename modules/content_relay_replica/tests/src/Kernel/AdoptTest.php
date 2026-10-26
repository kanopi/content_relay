<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\content_relay\Event\Operation;
use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests adopting content a replica made from a copy already has.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class AdoptTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['content_translation', 'language'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'article')->setLanguageAlterable(TRUE)->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->useFakeOrigin();
  }

  /**
   * A node here, as copied from the origin.
   */
  private function copied(string $title, ?string $french = NULL): NodeInterface {
    $node = Node::create(['type' => 'article', 'title' => $title, 'uid' => 0, 'langcode' => 'en']);
    if ($french !== NULL) {
      $node->addTranslation('fr', ['title' => $french]);
    }
    $node->save();
    return $node;
  }

  /**
   * Has the origin list items, as their snapshots at a revision.
   *
   * @param list<array{0: array, 1: int}> $items
   *   Snapshots and revisions.
   */
  private function onOrigin(array $items): void {
    $hasher = $this->container->get('content_relay.revision_hasher');
    $entries = [];
    foreach ($items as [$snapshot, $revision]) {
      $entries[] = [
        'uuid' => $snapshot['uuid'],
        'revision' => $revision,
        'changed' => 1700000000 + $revision,
        'hash' => $hasher->hash($snapshot)->toArray(),
        'not_syncable' => FALSE,
      ];
    }
    usort($entries, fn (array $a, array $b) => strcmp($a['uuid'], $b['uuid']));
    $this->updateFakeOrigin(function (array &$config) use ($entries): void {
      $config['hashes'] = ['node:article' => $entries, 'taxonomy_term:tags' => []];
    });
  }

  /**
   * The sync state of an item.
   */
  private function state(string $uuid): ?array {
    return $this->container->get('content_relay_replica.sync_state')->get('node', $uuid);
  }

  /**
   * Identical copies are in sync; different ones become conflicts.
   */
  public function testAdopt(): void {
    $same = $this->copied('Same', 'Pareil');
    $changed = $this->copied('Changed here', 'Pareil aussi');
    $already = $this->copied('Already synced');
    // What the origin has: the same, French changed, and one not copied.
    $origin_changed = $this->snapshot($changed);
    $origin_changed['translations']['fr']['title'][0]['value'] = 'Changé sur l’origine';
    $missing = $this->originArticle('Not copied');
    $this->onOrigin([
      [$this->snapshot($same), 3],
      [$origin_changed, 4],
      [$this->snapshot($already), 1],
      [$missing, 1],
    ]);
    $this->container->get('content_relay_replica.sync_state')->save('node', $already->uuid(), [
      'bundle' => 'article',
      'source_site' => self::ORIGIN,
      'source_revision' => 1,
      'hash_algorithm' => 1,
      'source_hash' => str_repeat('0', 64),
      'source_translation_hashes' => [],
      'status' => SyncStatus::SYNCED,
    ]);

    // Before adopting, sync leaves the copies alone; the refused event is
    // retried once the item is adopted.
    $refused = $this->snapshot($same);
    $refused['translations']['en']['title'][0]['value'] = 'Same, sent before adopting';
    $result = $this->apply($this->event($refused, Operation::Update, 3));
    $this->assertSame('uuid_collision', $result->code);
    $this->assertSame('Same', $this->local($same->uuid())->label());

    $counts = $this->container->get('content_relay_replica.adopter')->run();
    ksort($counts);
    $this->assertSame(['adopted' => 1, 'adopted_edited' => 1, 'already_synced' => 1, 'missing' => 1, 'retried' => 1], $counts);
    $this->assertSame('Same, sent before adopting', $this->local($same->uuid())->label());
    $this->assertSame([], $this->container->get('content_relay_replica.failed_events')->getOpenForEntity('node', $same->uuid()));

    $state = $this->state($same->uuid());
    $this->assertSame(SyncStatus::SYNCED, $state['status']);
    $this->assertSame([3, ['en', 'fr']], [
      (int) $state['source_revision'],
      array_keys($state['source_translation_hashes']),
    ]);
    $this->assertNull($this->container->get('content_relay_replica.local_changes')->editedTranslations($this->local($same->uuid()), $state));

    $state = $this->state($changed->uuid());
    $this->assertSame(SyncStatus::CONFLICT, $state['status']);
    $this->assertStringContainsString('(fr)', $state['last_error']);
    $this->assertSame(['fr'], $this->container->get('content_relay_replica.local_changes')->editedTranslations($this->local($changed->uuid()), $state));
    $this->assertSame('Already synced', $this->local($already->uuid())->label());
    $this->assertNull($this->state($missing['uuid']));

    // Put right here, an unresolved difference is adopted as in sync when
    // adopting again.
    $fixed = $this->copied('Fixed later', 'Pareil');
    $origin_fixed = $this->snapshot($fixed);
    $fixed->getTranslation('fr')->setTitle('Pas encore')->save();
    $this->onOrigin([
      [$this->snapshot($same), 3],
      [$origin_changed, 4],
      [$this->snapshot($already), 1],
      [$missing, 1],
      [$origin_fixed, 2],
    ]);
    // The earlier difference is compared again too, and still differs.
    $counts = $this->container->get('content_relay_replica.adopter')->run();
    ksort($counts);
    $this->assertSame(['adopted_edited' => 2, 'already_synced' => 2, 'missing' => 1], $counts);
    $fixed->getTranslation('fr')->setTitle('Pareil')->save();
    $counts = $this->container->get('content_relay_replica.adopter')->run();
    $this->assertSame(1, $counts['adopted'] ?? 0);
    $this->assertSame(SyncStatus::SYNCED, $this->state($fixed->uuid())['status']);

    // Adopted, the identical copy follows the origin like any synced item.
    $updated = $this->snapshot($same);
    $updated['translations']['en']['title'][0]['value'] = 'Same, updated';
    $result = $this->apply($this->event($updated, Operation::Update, 5));
    $this->assertSame([ApplyResult::APPLIED, NULL], [$result->result, $result->code]);
    $this->assertSame('Same, updated', $this->local($same->uuid())->label());
    $this->assertSame(SyncStatus::SYNCED, $this->state($same->uuid())['status']);

    // The one that differed goes through the conflict rules: by default the
    // origin's version is saved, the copy kept in the revision before.
    $result = $this->apply($this->event($origin_changed, Operation::Update, 6));
    $this->assertSame(ApplyResult::APPLIED, $result->result);
    $local = $this->local($changed->uuid());
    $this->assertSame('Changé sur l’origine', $local->getTranslation('fr')->label());
    $state = $this->state($changed->uuid());
    $kept = $this->container->get('entity_type.manager')->getStorage('node')->loadRevision($state['conflict_revision']);
    $this->assertInstanceOf(NodeInterface::class, $kept);
    $this->assertSame('Pareil aussi', $kept->getTranslation('fr')->label());

    // Adopting again changes nothing: the conflict above was resolved by the
    // origin's update.
    $this->assertSame(['already_synced' => 4, 'missing' => 1], $this->container->get('content_relay_replica.adopter')->run());
  }

}
