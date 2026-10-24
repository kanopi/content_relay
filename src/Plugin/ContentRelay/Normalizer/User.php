<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\ContentRelay\Normalizer;

use Drupal\content_relay\Attribute\ContentRelayNormalizer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\content_relay\Serialization\FieldNormalizerManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Users: everything but credentials, activity and, by default, roles.
 *
 * Passwords never leave a site (password fields are excluded for every entity
 * type). Last access and login times are this site's activity, and the
 * address a user first registered with is history, not profile. Roles are a
 * permission decision each site makes for itself unless
 * content_relay.settings:users.sync_roles says otherwise. Sessions are not
 * fields and are never involved.
 */
#[ContentRelayNormalizer(
  id: 'user',
  label: new TranslatableMarkup('User'),
  entity_types: ['user'],
)]
class User extends GenericContentEntityNormalizer {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    FieldNormalizerManager $field_normalizers,
    EntityFieldManagerInterface $entity_field_manager,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $language_manager, $field_normalizers, $entity_field_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('plugin.manager.content_relay_field_normalizer'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLocalFieldNames(ContentEntityTypeInterface $entity_type): array {
    $local = ['pass', 'access', 'login', 'init'];
    if (!$this->configFactory->get('content_relay.settings')->get('users.sync_roles')) {
      $local[] = 'roles';
    }
    return [...parent::getLocalFieldNames($entity_type), ...$local];
  }

}
