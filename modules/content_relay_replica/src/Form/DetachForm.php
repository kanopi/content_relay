<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\ItemDetacher;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Detaches one synced item.
 */
final class DetachForm extends ConfirmFormBase {

  /**
   * The item's sync state.
   */
  protected array $state;

  /**
   * The item's label.
   */
  protected string $label;

  public function __construct(
    protected ItemDetacher $detacher,
    protected SyncStateStore $syncState,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.detacher'),
      $container->get('content_relay_replica.sync_state'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_detach';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL, ?string $uuid = NULL): array {
    $state = $entity_type_id === NULL || $uuid === NULL ? NULL : $this->syncState->get($entity_type_id, $uuid);
    if ($state === NULL || $state['detached_at'] !== NULL) {
      throw new NotFoundHttpException();
    }
    $this->state = $state;
    $this->label = $this->label($entity_type_id, $uuid);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Detach %label?', ['%label' => $this->label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t("Updates from the origin will no longer be applied to this item, and the origin will stop sending them. You can edit it freely. Reattaching later lets you choose between your edits and the origin's version.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Detach');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('view.content_relay_replica_report.page_1');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->detacher->detach($this->state['entity_type'], $this->state['uuid']);
    $this->messenger()->addStatus($this->t('%label is detached.', ['%label' => $this->label]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * The local item's label, or its UUID if it is gone.
   */
  protected function label(string $entity_type_id, string $uuid): string {
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    return $entity === NULL ? $uuid : (string) $entity->label();
  }

}
