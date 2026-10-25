<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\File\FileSync;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fetches a synced file again, or the missing files of a synced item.
 *
 * Each file is fetched with a fresh link from the origin's file endpoint, in
 * the request, so the result shows at once.
 */
final class PullFilesForm extends ConfirmFormBase {

  /**
   * The item.
   */
  protected ContentEntityInterface $entity;

  public function __construct(
    protected FileSync $files,
    protected SyncStateStore $syncState,
    protected EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.file_sync'),
      $container->get('content_relay_replica.sync_state'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_pull_files';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL, ?string $uuid = NULL): array {
    /** @var \Drupal\Core\Entity\EntityInterface|null $entity */
    $entity = $entity_type_id === NULL || $uuid === NULL ? NULL : $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);
    if (!$entity instanceof ContentEntityInterface || $this->syncState->get($entity_type_id, $uuid) === NULL) {
      throw new NotFoundHttpException();
    }
    $this->entity = $entity;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->entity->getEntityTypeId() === 'file'
      ? $this->t('Pull %label from the origin again?', ['%label' => $this->entity->label()])
      : $this->t('Pull the missing files of %label from the origin?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Pull');
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
    $uuids = $this->entity->getEntityTypeId() === 'file'
      ? [(string) $this->entity->uuid()]
      : $this->files->queueMissingFor($this->entity);
    if ($uuids === []) {
      $this->messenger()->addStatus($this->t('No files are missing.'));
    }
    $failed = 0;
    foreach ($uuids as $uuid) {
      $failed += (int) ($this->files->pull($uuid) !== FileSync::DONE);
    }
    if ($uuids !== []) {
      $this->messenger()->addStatus($this->formatPlural(count($uuids) - $failed, 'Fetched 1 file.', 'Fetched @count files.'));
    }
    if ($failed > 0) {
      $this->messenger()->addError($this->formatPlural($failed, '1 file could not be fetched; it will be retried. See the log for why.', '@count files could not be fetched; they will be retried. See the log for why.'));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
