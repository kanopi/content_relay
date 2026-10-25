<?php

declare(strict_types=1);

namespace Drupal\content_relay\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyRotation;
use Drupal\content_relay\Key\KeyStore;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for this site's Content Relay keys.
 *
 * Each command exits non-zero when what it was asked to do did not happen.
 */
final class KeyCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'content_relay.key_rotation')]
    private readonly KeyRotation $rotation,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Lists this site's Content Relay keys.
   */
  #[CLI\Command(name: 'content-relay:keys', aliases: ['cr-keys'])]
  #[CLI\FieldLabels(labels: ['key' => 'Key', 'purpose' => 'Purpose', 'status' => 'Status', 'provider' => 'Provider'])]
  public function keys(): RowsOfFields {
    $rows = [];
    foreach (KeyPurpose::cases() as $purpose) {
      foreach ($this->entityTypeManager->getStorage('key')->loadByProperties(['key_type' => $purpose->keyTypeId()]) as $key) {
        /** @var \Drupal\key\KeyInterface $key */
        $rows[] = [
          'key' => $key->id(),
          'purpose' => $purpose->value,
          'status' => KeyStore::statusOf($key),
          'provider' => (string) $key->get('key_provider'),
        ];
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * Generates a new key pair into a file, as an active (not yet used) key.
   */
  #[CLI\Command(name: 'content-relay:generate-key', aliases: ['cr-generate-key'])]
  #[CLI\Argument(name: 'purpose', description: 'signing or encryption.')]
  #[CLI\Argument(name: 'key_id', description: 'Machine name for the new key entity.')]
  #[CLI\Argument(name: 'path', description: 'File to write the private key to, outside the web root.')]
  #[CLI\Usage(name: 'drush content-relay:generate-key signing relay_signing_2026 ../private/relay_signing_2026.key', description: 'Create the next signing key.')]
  public function generate(string $purpose, string $key_id, string $path): int {
    $pair = $this->rotation->generate($this->purpose($purpose), $key_id, $path);
    $this->logger()->success(dt('Generated @purpose key @id in @path. Make it primary with content-relay:rotate-key.', [
      '@purpose' => $purpose,
      '@id' => $pair->id(),
      '@path' => $path,
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Makes an active key primary, after telling peers about it.
   */
  #[CLI\Command(name: 'content-relay:rotate-key', aliases: ['cr-rotate-key'])]
  #[CLI\Argument(name: 'purpose', description: 'signing or encryption.')]
  #[CLI\Argument(name: 'key_id', description: 'The key entity to make primary.')]
  public function rotate(string $purpose, string $key_id): int {
    try {
      $results = $this->rotation->rotate($this->purpose($purpose), $key_id);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->report($results);
    $this->logger()->success(dt('@key is now primary. Retire the previous key once every peer has confirmed.', ['@key' => $key_id]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Retires a key, and tells peers to stop accepting it.
   */
  #[CLI\Command(name: 'content-relay:retire-key', aliases: ['cr-retire-key'])]
  #[CLI\Argument(name: 'purpose', description: 'signing or encryption.')]
  #[CLI\Argument(name: 'key_id', description: 'The key entity to retire.')]
  public function retire(string $purpose, string $key_id): int {
    try {
      $results = $this->rotation->retire($this->purpose($purpose), $key_id);
    }
    catch (\RuntimeException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->report($results);
    $this->logger()->success(dt('@key is retired.', ['@key' => $key_id]));
    return in_array(FALSE, $results, TRUE) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Prints which peers confirmed.
   */
  private function report(array $results): void {
    foreach ($results as $peer => $confirmed) {
      $confirmed
        ? $this->logger()->notice(dt('@peer confirmed.', ['@peer' => $peer]))
        : $this->logger()->warning(dt('@peer did not confirm; it will be told when it next accepts a contract or update.', ['@peer' => $peer]));
    }
  }

  /**
   * A purpose from its name.
   */
  private function purpose(string $name): KeyPurpose {
    return KeyPurpose::tryFrom($name) ?? throw new \InvalidArgumentException('Purpose must be signing or encryption.');
  }

}
