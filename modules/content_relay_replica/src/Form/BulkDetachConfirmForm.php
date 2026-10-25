<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\ItemDetacher;
use Drupal\content_relay_replica\Plugin\views\field\BulkDetachForm;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms detaching or reattaching items selected in the report.
 */
final class BulkDetachConfirmForm extends ConfirmFormBase {

  /**
   * Detach or reattach.
   */
  protected string $operation;

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('tempstore.private'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_bulk_detach';
  }

  /**
   * The selected items.
   */
  private function items(): array {
    return (array) $this->tempStoreFactory->get(BulkDetachForm::TEMPSTORE)->get(BulkDetachForm::SELECTION);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $operation = NULL): array {
    if (!in_array($operation, ['detach', 'reattach'], TRUE)) {
      throw new NotFoundHttpException();
    }
    $this->operation = $operation;
    if ($operation === 'reattach') {
      $form['accept_origin'] = [
        '#type' => 'checkbox',
        '#title' => $this->t("Replace local changes with the origin's version"),
        '#description' => $this->t('Without this, items changed here since they were detached stay detached.'),
      ];
    }
    $form = parent::buildForm($form, $form_state);
    if ($this->items() === []) {
      $form['actions']['submit']['#disabled'] = TRUE;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    $count = count($this->items());
    return $this->operation === 'detach'
      ? $this->formatPlural($count, 'Detach 1 item?', 'Detach @count items?')
      : $this->formatPlural($count, 'Reattach 1 item?', 'Reattach @count items?');
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
    $items = $this->items();
    $this->tempStoreFactory->get(BulkDetachForm::TEMPSTORE)->delete(BulkDetachForm::SELECTION);
    batch_set(ItemDetacher::batch($items, $this->operation, (bool) $form_state->getValue('accept_origin')));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
