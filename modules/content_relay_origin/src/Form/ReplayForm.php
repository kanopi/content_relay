<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay_origin\EventStatus;
use Drupal\content_relay_origin\Outbox;
use Drupal\content_relay_origin\ReplayService;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms replaying a failed event.
 */
final class ReplayForm extends ConfirmFormBase {

  /**
   * The entry.
   */
  protected array $row = [];

  public function __construct(
    protected Outbox $outbox,
    protected ReplayService $replay,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.outbox'),
      $container->get('content_relay_origin.replay'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_replay';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $row_id = NULL): array {
    $row = $row_id === NULL ? NULL : $this->outbox->load($row_id);
    if ($row === NULL || !in_array($row['status'], EventStatus::REPLAYABLE, TRUE)) {
      throw new NotFoundHttpException();
    }
    $this->row = $row;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Replay event log entry @id?', ['@id' => $this->row['id']]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t("The item's current version is sent to the replica again as a new event, or a delete if the item is gone. This entry is marked as replayed.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Replay');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('content_relay_origin.event', ['row_id' => $this->row['id']]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $new = $this->replay->replay((int) $this->row['id']);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $this->messenger()->addStatus($this->t('Replayed as event log entry @id.', ['@id' => $new]));
    $form_state->setRedirect('content_relay_origin.event', ['row_id' => $new]);
  }

}
