<?php

declare(strict_types=1);

namespace Drupal\content_relay\Plugin\KeyType;

use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay\Key\KeyStore;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\key\Plugin\KeyTypeBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared behaviour of the Content Relay key types.
 *
 * Each key entity of one of these types is one of this site's key pairs. Its
 * status, set on the key's own edit form, says how Content Relay uses it:
 *
 * - primary: signs (or is offered for) new messages. One per type.
 * - active: still accepted, during a rotation.
 * - retired: kept for the record, never used.
 *
 * The value is the purpose prefix followed by the base64 secret key, as
 * produced by generateKeyValue(). Keys must use a provider that keeps the
 * value outside configuration and the database, such as a file or an
 * environment variable.
 */
abstract class ContentRelayKeyTypeBase extends KeyTypeBase implements KeyPluginFormInterface {

  /**
   * The key repository, to check for another primary key.
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->keyRepository = $container->get('key.repository');
    return $instance;
  }

  /**
   * The purpose of keys of this type.
   */
  abstract public static function purpose(): KeyPurpose;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['status' => KeyStore::ACTIVE];
  }

  /**
   * {@inheritdoc}
   */
  public static function generateKeyValue(array $configuration) {
    return KeyPair::generate(static::purpose())[1];
  }

  /**
   * {@inheritdoc}
   */
  public function validateKeyValue(array $form, FormStateInterface $form_state, $key_value) {
    if ($key_value === NULL || $key_value === '') {
      return;
    }
    try {
      KeyPair::fromMaterial(static::purpose(), (string) $key_value);
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('key_type', $this->t('This is not a Content Relay @purpose key: @reason', [
        '@purpose' => static::purpose()->value,
        '@reason' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Status'),
      '#options' => [
        KeyStore::PRIMARY => $this->t('Primary: used for new messages'),
        KeyStore::ACTIVE => $this->t('Active: still accepted, during a key rotation'),
        KeyStore::RETIRED => $this->t('Retired: no longer used or accepted'),
      ],
      '#default_value' => $this->getConfiguration()['status'],
      '#required' => TRUE,
      '#description' => $this->t('Store the value in a file outside the web root or in an environment variable. Content Relay refuses keys kept in configuration or the database.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $complete = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $provider = (string) $complete->getValue('key_provider');
    if (in_array($provider, KeyStore::REFUSED_PROVIDERS, TRUE)) {
      $form_state->setErrorByName('status', $this->t('Content Relay private keys cannot use the "@provider" provider, which keeps the value in configuration or the database. Use a file or environment variable provider.', ['@provider' => $provider]));
    }
    if ($form_state->getValue('status') === KeyStore::PRIMARY) {
      $own_id = (string) $complete->getValue('id');
      foreach ($this->keyRepository->getKeysByType($this->getPluginId()) as $id => $key) {
        if ($id !== $own_id && KeyStore::statusOf($key) === KeyStore::PRIMARY) {
          $form_state->setErrorByName('status', $this->t('Key %key is already the primary key of this type. Make it active first.', ['%key' => $key->label()]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration(['status' => (string) $form_state->getValue('status')]);
  }

}
