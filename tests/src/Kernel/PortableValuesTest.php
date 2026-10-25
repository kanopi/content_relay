<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay\Kernel;

use Drupal\content_relay\Serialization\DenormalizationContext;
use Drupal\content_relay\Serialization\NormalizationContext;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests values that hide local IDs or local state, on several entity types.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class PortableValuesTest extends KernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_moderation',
    'content_relay',
    'content_translation',
    'field',
    'filter',
    'key',
    'language',
    'link',
    'menu_link_content',
    'node',
    'path_alias',
    'system',
    'text',
    'user',
    'workflows',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'menu_link_content', 'path_alias', 'content_moderation_state'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['filter', 'node', 'language', 'content_moderation', 'system']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_link', 'type' => 'link'])->save();
    FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_link'])->save();
  }

  /**
   * The serializer.
   */
  private function serializer() {
    return $this->container->get('content_relay.entity_serializer');
  }

  /**
   * An article.
   */
  private function article(string $title = 'Target'): Node {
    $node = Node::create(['type' => 'article', 'title' => $title, 'uid' => 0, 'langcode' => 'en']);
    $node->save();
    return $node;
  }

  /**
   * Entity URIs in links travel by UUID and come back as local IDs.
   */
  public function testLinks(): void {
    $target = $this->article();
    $id = $target->id();
    $uuid = $target->uuid();
    $cases = [
      "entity:node/$id" => "entity:node/{cr:node:$uuid}",
      "internal:/node/$id/edit?x=1#top" => "internal:/node/{cr:node:$uuid}/edit?x=1#top",
      "route:entity.node.canonical;node=$id" => "route:entity.node.canonical;node={cr:node:$uuid}",
      'internal:/node/add' => 'internal:/node/add',
      'internal:/user/login' => 'internal:/user/login',
      'https://example.com/node/5' => 'https://example.com/node/5',
    ];
    foreach ($cases as $uri => $portable) {
      $node = Node::create(['type' => 'article', 'title' => 'Linker', 'uid' => 0, 'field_link' => ['uri' => $uri]]);
      $context = new NormalizationContext();
      $snapshot = $this->serializer()->normalize($node, $context);
      $this->assertSame($portable, $snapshot['shared']['field_link'][0]['uri'] ?? $snapshot['translations']['en']['field_link'][0]['uri'], $uri);
      $back = $this->serializer()->denormalize(['uuid' => $this->container->get('uuid')->generate()] + $snapshot);
      $this->assertSame($uri, $back->get('field_link')->uri, $uri);
    }
  }

  /**
   * A link to an entity that has not arrived yet makes the item wait.
   */
  public function testLinkToMissingEntityWaits(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Linker',
      'uid' => 0,
      'field_link' => ['uri' => 'entity:node/' . $this->article()->id()],
    ]);
    $snapshot = $this->serializer()->normalize($node);
    $field = isset($snapshot['shared']['field_link']) ? 'shared' : 'translations';
    $missing = '1f0b5b9c-7b8a-4d5e-9f00-112233445566';
    if ($field === 'shared') {
      $snapshot['shared']['field_link'][0]['uri'] = "entity:node/{cr:node:$missing}";
    }
    else {
      $snapshot['translations']['en']['field_link'][0]['uri'] = "entity:node/{cr:node:$missing}";
    }
    $context = new DenormalizationContext();
    $this->serializer()->denormalize(['uuid' => $this->container->get('uuid')->generate()] + $snapshot, $context);
    $this->assertArrayHasKey("node:$missing", $context->missingReferences);

    // Outside the subscription, it is dropped instead, and the drop recorded.
    $context = new DenormalizationContext(external: ["node:$missing" => TRUE]);
    $copy = $this->serializer()->denormalize(['uuid' => $this->container->get('uuid')->generate()] + $snapshot, $context);
    $this->assertSame([], $context->missingReferences);
    $this->assertTrue($copy->get('field_link')->isEmpty());
    $this->assertCount(1, $context->substitutions);
  }

  /**
   * Path aliases point at the right local entity, in their language.
   */
  public function testPathAlias(): void {
    $target = $this->article();
    $alias = PathAlias::create(['path' => '/node/' . $target->id(), 'alias' => '/about', 'langcode' => 'fr']);
    $alias->save();
    $context = new NormalizationContext();
    $snapshot = json_decode(json_encode($this->serializer()->normalize($alias, $context)), TRUE);
    $this->assertSame('/node/{cr:node:' . $target->uuid() . '}', $snapshot['shared']['path'][0]['value'] ?? $snapshot['translations']['fr']['path'][0]['value']);
    $this->assertArrayHasKey('node:' . $target->uuid(), $context->references);

    $alias->delete();
    $copy = $this->serializer()->denormalize($snapshot);
    $copy->save();
    $this->assertSame(['/node/' . $target->id(), '/about', 'fr'], [
      $copy->getPath(),
      $copy->getAlias(),
      $copy->language()->getId(),
    ]);
  }

  /**
   * Menu link hierarchy is rebuilt by UUID, children waiting for parents.
   */
  public function testMenuHierarchy(): void {
    $target = $this->article();
    $parent = MenuLinkContent::create([
      'title' => 'Parent',
      'menu_name' => 'main',
      'link' => ['uri' => 'entity:node/' . $target->id()],
    ]);
    $parent->save();
    $child = MenuLinkContent::create([
      'title' => 'Child',
      'menu_name' => 'main',
      'parent' => $parent->getPluginId(),
      'link' => ['uri' => 'internal:/node/' . $target->id()],
    ]);
    $child->save();

    $context = new NormalizationContext();
    $parent_snapshot = json_decode(json_encode($this->serializer()->normalize($parent)), TRUE);
    $child_snapshot = json_decode(json_encode($this->serializer()->normalize($child, $context)), TRUE);
    $this->assertArrayHasKey('menu_link_content:' . $parent->uuid(), $context->references, 'The parent is a dependency.');
    $this->assertArrayHasKey('node:' . $target->uuid(), $context->references);

    $child->delete();
    $parent->delete();
    $this->container->get('content_relay.uuid_resolver')->reset();

    // The child arrives first: it waits for its parent.
    $context = new DenormalizationContext();
    $this->serializer()->denormalize($child_snapshot, $context);
    $this->assertArrayHasKey('menu_link_content:' . $parent_snapshot['uuid'], $context->missingReferences);

    $this->serializer()->denormalize($parent_snapshot)->save();
    $this->container->get('content_relay.uuid_resolver')->reset();
    $copy = $this->serializer()->denormalize($child_snapshot);
    $copy->save();
    $this->assertSame('menu_link_content:' . $parent_snapshot['uuid'], $copy->getParentId());
    $this->assertSame('internal:/node/' . $target->id(), $copy->get('link')->uri);
  }

  /**
   * Users sync without credentials, activity or roles, and keep local ones.
   */
  public function testUsers(): void {
    Role::create(['id' => 'editor', 'label' => 'Editor'])->save();
    $user = User::create([
      'name' => 'ana',
      'mail' => 'ana@example.com',
      'pass' => 'secret',
      'roles' => ['editor'],
      'status' => 1,
      'access' => 1700000000,
      'login' => 1700000000,
      'init' => 'first@example.com',
    ]);
    $user->save();
    $snapshot = $this->serializer()->normalize($user);
    $fields = $snapshot['shared'] + $snapshot['translations'][$snapshot['default_langcode']];
    foreach (['pass', 'roles', 'access', 'login', 'init'] as $local) {
      $this->assertArrayNotHasKey($local, $fields, "$local is not synced.");
    }
    $this->assertSame('ana@example.com', $fields['mail'][0]['value']);
    $this->assertStringNotContainsString('secret', json_encode($snapshot));

    // Applied to an existing account, local roles and password are kept.
    $fields_key = isset($snapshot['shared']['mail']) ? 'shared' : 'translations';
    $changed = $snapshot;
    if ($fields_key === 'shared') {
      $changed['shared']['mail'][0]['value'] = 'ana@example.org';
    }
    else {
      $changed['translations'][$snapshot['default_langcode']]['mail'][0]['value'] = 'ana@example.org';
    }
    $updated = $this->serializer()->denormalize($changed, NULL, $user);
    $updated->save();
    $saved = User::load($user->id());
    $this->assertSame('ana@example.org', $saved->getEmail());
    $this->assertTrue($saved->hasRole('editor'));
    $this->assertTrue($this->container->get('password')->check('secret', $saved->getPassword()));

    // Roles sync when a site asks for it.
    $this->config('content_relay.settings')->set('users.sync_roles', TRUE)->save();
    $this->container->get('plugin.manager.content_relay_normalizer')->clearCachedDefinitions();
    $with_roles = $this->serializer()->normalize($saved);
    $this->assertSame([['target_type' => 'user_role', 'target_id' => 'editor']], ($with_roles['shared'] + $with_roles['translations'][$with_roles['default_langcode']])['roles']);
  }

  /**
   * Moderation state travels per translation.
   *
   * Drafts on top of a published item (forward revisions) are sent too, as
   * the latest revision; ModerationTest on the replica covers those.
   */
  public function testModerationState(): void {
    $workflow = $this->createEditorialWorkflow();
    /** @var \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface $type */
    $type = $workflow->getTypePlugin();
    $type->addEntityTypeAndBundle('node', 'article');
    $workflow->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'article', TRUE);
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('entity_type.bundle.info')->clearCachedBundles();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Hello',
      'uid' => 0,
      'langcode' => 'en',
      'moderation_state' => 'published',
    ]);
    $node->save();
    $node->addTranslation('fr', ['title' => 'Bonjour', 'moderation_state' => 'published'])->save();
    $draft = Node::create([
      'type' => 'article',
      'title' => 'Not yet',
      'uid' => 0,
      'langcode' => 'en',
      'moderation_state' => 'draft',
    ]);
    $draft->save();

    foreach ([[$node->id(), 'published', TRUE], [$draft->id(), 'draft', FALSE]] as [$id, $state, $published]) {
      $original = Node::load($id);
      $snapshot = json_decode(json_encode($this->serializer()->normalize($original)), TRUE);
      foreach (array_keys($snapshot['translations']) as $langcode) {
        $this->assertSame($state, $snapshot['translations'][$langcode]['moderation_state'][0]['value']);
      }
      $original->delete();
      $copy = $this->serializer()->denormalize($snapshot);
      $copy->save();
      $saved = Node::load($copy->id());
      foreach (array_keys($snapshot['translations']) as $langcode) {
        $this->assertSame($state, $saved->getTranslation($langcode)->get('moderation_state')->value);
        $this->assertSame($published, $saved->getTranslation($langcode)->isPublished());
      }
    }
  }

}
