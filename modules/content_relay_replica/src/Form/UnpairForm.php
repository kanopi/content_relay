<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\Entity\ContentRelayOriginPairing;
use Drupal\content_relay_replica\Unpairer;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Ends a pairing, keeping or deleting what came from the origin.
 */
final class UnpairForm extends ConfirmFormBase {

  /**
   * The pairing.
   */
  protected ContentRelayOriginPairing $pairing;

  public function __construct(
    protected Unpairer $unpairer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('content_relay_replica.unpairer'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_unpair';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentRelayOriginPairing $content_relay_origin_pairing = NULL): array {
    $this->pairing = $content_relay_origin_pairing;
    $counts = $this->unpairer->count((string) $this->pairing->id());
    $form['what'] = [
      '#type' => 'radios',
      '#title' => $this->t('What came from %origin', ['%origin' => $this->pairing->label()]),
      '#required' => TRUE,
      '#options' => [
        'keep' => $this->t('Keep it as content of this site, no longer synced (@count items)', ['@count' => $counts['received']]),
        'delete' => $this->t('Delete it (@count items)', ['@count' => $counts['received']]),
      ],
      '#description' => $counts['detached'] > 0
        ? $this->t("@count detached item(s) are already this site's own and are kept either way.", ['@count' => $counts['detached']])
        : NULL,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Unpair from %origin?', ['%origin' => $this->pairing->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The origin is told and stops sending. Its contract, settings, failures and reconciliation history here are removed. To receive from it again, pair with a new code.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Unpair');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('content_relay_replica.origins');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $pairing = (string) $this->pairing->id();
    $label = (string) $this->pairing->label();
    if (!$this->unpairer->notifyOrigin($pairing)) {
      $this->messenger()->addWarning($this->t('%origin could not be told; it may keep sending until it is revoked there, and its events are refused here.', ['%origin' => $label]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
    if ($form_state->getValue('what') === 'delete') {
      batch_set([
        'title' => $this->t('Unpairing from @origin', ['@origin' => $label]),
        'operations' => [
          [[self::class, 'deleteStep'], [$pairing]],
          [[self::class, 'finishStep'], [$pairing, $label]],
        ],
      ]);
      return;
    }
    self::finished($label, $this->unpairer->finish($pairing), NULL);
  }

  /**
   * Batch operation: deletes a page of the origin's items.
   */
  public static function deleteStep(string $pairing, &$context): void {
    $page = \Drupal::service('content_relay_replica.unpairer')->deletePage($pairing);
    $context['results']['deleted'] = ($context['results']['deleted'] ?? 0) + $page['deleted'];
    $context['message'] = new TranslatableMarkup('@deleted item(s) deleted, @remaining to go.', [
      '@deleted' => $context['results']['deleted'],
      '@remaining' => $page['remaining'],
    ]);
    $context['finished'] = $page['remaining'] > 0 && $page['deleted'] > 0 ? 0.5 : 1;
  }

  /**
   * Batch operation: forgets the pairing.
   */
  public static function finishStep(string $pairing, string $label, &$context): void {
    self::finished($label, \Drupal::service('content_relay_replica.unpairer')->finish($pairing), (int) ($context['results']['deleted'] ?? 0));
  }

  /**
   * Says what happened.
   */
  protected static function finished(string $label, int $kept, ?int $deleted): void {
    \Drupal::messenger()->addStatus($deleted === NULL
      ? new TranslatableMarkup('Unpaired from %origin. @kept item(s) are kept as content of this site.', [
        '%origin' => $label,
        '@kept' => $kept,
      ])
      : new TranslatableMarkup('Unpaired from %origin. @deleted item(s) deleted; @kept detached item(s) kept.', [
        '%origin' => $label,
        '@deleted' => $deleted,
        '@kept' => $kept,
      ]));
  }

}
