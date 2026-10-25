<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\ItemDetacher;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reattaches one detached item, choosing what happens to local edits.
 */
final class ReattachForm extends FormBase {

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
    return 'content_relay_replica_reattach';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL, ?string $uuid = NULL): array {
    $state = $entity_type_id === NULL || $uuid === NULL ? NULL : $this->syncState->get($entity_type_id, $uuid);
    if ($state === NULL || $state['detached_at'] === NULL) {
      throw new NotFoundHttpException();
    }
    $label = $this->label($entity_type_id, $uuid);
    $form_state->set('item', [$entity_type_id, $uuid, $label]);
    $edited = $this->detacher->localEdits($entity_type_id, $uuid);

    $form['#title'] = $this->t('Reattach %label', ['%label' => $label]);
    if ($edited === NULL) {
      $form['intro'] = ['#markup' => '<p>' . $this->t('This item has not been changed here since it was detached. Reattaching resumes updates from the origin, starting with its current version.') . '</p>'];
    }
    else {
      $form['intro'] = [
        '#markup' => '<p>' . $this->t("This item was changed here since it was detached. Accepting the origin's version replaces your changes with the origin's current version. Keeping your changes leaves it detached.") . '</p>',
      ];
      $form['edited'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Translations that differ from the last version received'),
        '#items' => $edited === [] ? [$this->t('Translations were added or removed.')] : $edited,
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['accept'] = [
      '#type' => 'submit',
      '#value' => $edited === NULL ? $this->t('Reattach') : $this->t('Accept origin version'),
      '#name' => 'accept',
    ];
    if ($edited !== NULL) {
      $form['actions']['keep'] = [
        '#type' => 'submit',
        '#value' => $this->t('Keep local and stay detached'),
        '#name' => 'keep',
      ];
    }
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('view.content_relay_replica_report.page_1'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$entity_type_id, $uuid, $label] = $form_state->get('item');
    $accept = ($form_state->getTriggeringElement()['#name'] ?? '') === 'accept';
    if ($this->detacher->reattach($entity_type_id, $uuid, $accept)) {
      $this->messenger()->addStatus($this->t("%label is reattached. The origin's version will arrive shortly.", ['%label' => $label]));
    }
    else {
      $this->messenger()->addStatus($this->t('%label stays detached with your changes.', ['%label' => $label]));
    }
    $form_state->setRedirect('view.content_relay_replica_report.page_1');
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
