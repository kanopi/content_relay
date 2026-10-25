<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Plugin\views\field;

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
 * Checkboxes on synced item rows, to detach or reattach the selected ones.
 */
#[ViewsField('content_relay_bulk_detach')]
final class BulkDetachForm extends FieldPluginBase {

  use UncacheableFieldHandlerTrait;

  public const TEMPSTORE = 'content_relay_replica';
  public const SELECTION = 'bulk_detach_selection';

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
   * Builds the checkboxes and the action select.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state): void {
    $form['#cache']['max-age'] = 0;
    $form['#attached']['library'][] = 'core/drupal.tableselect';
    if (empty($this->view->result) || !$this->view->getUser()->hasPermission('detach content relay items')) {
      unset($form['actions']);
      return;
    }
    $form[$this->options['id']]['#tree'] = TRUE;
    foreach ($this->view->result as $index => $row) {
      $form[$this->options['id']][$index] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select this row'),
        '#title_display' => 'invisible',
        '#return_value' => $row->{$this->aliases['entity_type']} . ':' . $row->{$this->field_alias},
      ];
    }
    $form['header'][$this->options['id']] = ['#type' => 'container', '#weight' => -100];
    $form['header'][$this->options['id']]['operation'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => ['detach' => $this->t('Detach'), 'reattach' => $this->t('Reattach')],
    ];
    $form['actions']['submit']['#value'] = $this->t('Apply to selected items');
  }

  /**
   * Requires a selection.
   */
  public function viewsFormValidate(array &$form, FormStateInterface $form_state): void {
    if ($this->selected($form_state) === []) {
      $form_state->setErrorByName('', $this->t('Select at least one item.'));
    }
  }

  /**
   * Keeps the selection and goes to the confirmation.
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('step') !== 'views_form_views_form') {
      return;
    }
    $operation = $form_state->getValue('operation') === 'reattach' ? 'reattach' : 'detach';
    $this->tempStoreFactory->get(self::TEMPSTORE)->set(self::SELECTION, $this->selected($form_state));
    $form_state->setRedirectUrl(Url::fromRoute('content_relay_replica.bulk_detach', ['operation' => $operation]));
  }

  /**
   * The items selected, as "entity_type:uuid".
   *
   * @return string[]
   *   Items.
   */
  private function selected(FormStateInterface $form_state): array {
    $input = $form_state->getUserInput()[$this->options['id']] ?? [];
    return array_values(array_filter((array) $input, fn ($value) => is_string($value) && str_contains($value, ':')));
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

}
