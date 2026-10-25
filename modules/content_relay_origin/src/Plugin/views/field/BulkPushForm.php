<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\field\UncacheableFieldHandlerTrait;
use Drupal\views\Plugin\views\style\Table;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checkboxes on status report rows, and a button to push the selected ones.
 *
 * The selection is kept in the user's private temporary store and confirmed
 * on the bulk push page, which shows the count per replica before anything
 * is recorded.
 */
#[ViewsField('content_relay_bulk_push')]
final class BulkPushForm extends FieldPluginBase {

  use UncacheableFieldHandlerTrait;

  public const TEMPSTORE = 'content_relay_origin';
  public const SELECTION = 'bulk_push_selection';

  /**
   * The private temporary store.
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->tempStoreFactory = $container->get('tempstore.private');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {
    parent::preRender($values);
    if ($this->view->style_plugin instanceof Table) {
      $this->options['element_label_class'] .= 'select-all';
      $this->options['label'] = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $row->index . '-->';
  }

  /**
   * Builds the checkboxes and the button.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state): void {
    $form['#cache']['max-age'] = 0;
    $form['#attached']['library'][] = 'core/drupal.tableselect';
    if (empty($this->view->result) || !$this->view->getUser()->hasPermission('bulk push content relay items')) {
      unset($form['actions']);
      return;
    }
    $form[$this->options['id']]['#tree'] = TRUE;
    foreach ($this->view->result as $index => $row) {
      $form[$this->options['id']][$index] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select this row'),
        '#title_display' => 'invisible',
        '#return_value' => (string) $row->{$this->field_alias},
      ];
    }
    $form['actions']['submit']['#value'] = $this->t('Push selected');
  }

  /**
   * Requires a selection.
   */
  public function viewsFormValidate(array &$form, FormStateInterface $form_state): void {
    if ($this->selected($form_state) === []) {
      $form_state->setErrorByName('', $this->t('Select at least one row to push.'));
    }
  }

  /**
   * Keeps the selection and goes to the confirmation.
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('step') !== 'views_form_views_form') {
      return;
    }
    $this->tempStoreFactory->get(self::TEMPSTORE)->set(self::SELECTION, $this->selected($form_state));
    $form_state->setRedirectUrl(Url::fromRoute('content_relay_origin.bulk_push', [], ['query' => ['selection' => 1]]));
  }

  /**
   * The report row IDs selected.
   *
   * @return int[]
   *   Row IDs.
   */
  private function selected(FormStateInterface $form_state): array {
    $input = $form_state->getUserInput()[$this->options['id']] ?? [];
    return array_values(array_map('intval', array_filter((array) $input)));
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

}
