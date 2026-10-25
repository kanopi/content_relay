<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin\Form;

use Drupal\content_relay_origin\ItemValidator;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Checks an item's hashes here and on every replica that gets it.
 */
final class ValidateForm extends FormBase {

  public function __construct(
    protected ItemValidator $validator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_origin.item_validator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_origin_validate';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL): array {
    $entity = $this->entity($entity_type_id);
    $form_state->set('entity_type_id', $entity_type_id);
    $form['#title'] = $this->t('Validate %label', ['%label' => $entity->label()]);
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Hash this item again here, and ask every replica that gets it what it has and whether its copy is unchanged. The status report is updated with the answers.') . '</p>',
    ];
    $result = $form_state->get('result');
    if (is_array($result)) {
      if ($result['origin_stale']) {
        $this->messenger()->addWarning($this->t('The stored hash here was out of date; the content was changed without a save. It has been recomputed.'));
      }
      $labels = ItemValidator::resultLabels();
      $rows = [];
      foreach ($result['replicas'] as $replica_id => $row) {
        $rows[] = [
          $row['label'] . ' (' . $replica_id . ')',
          $labels[$row['result']] ?? $row['result'],
          $row['revision'] ?? '-',
          implode(', ', $row['differing']) ?: '-',
          $row['detail'] ?? '',
        ];
      }
      $form['result'] = [
        '#type' => 'table',
        '#caption' => $this->t('Origin revision @revision', ['@revision' => $result['revision']]),
        '#header' => [
          $this->t('Replica'),
          $this->t('Result'),
          $this->t('Revision there'),
          $this->t('Translations behind'),
          $this->t('Detail'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No replica gets this item.'),
      ];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Validate')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('result', $this->validator->validate($this->entity($form_state->get('entity_type_id'))));
    $form_state->setRebuild();
  }

  /**
   * The item from the route.
   */
  protected function entity(?string $entity_type_id): ContentEntityInterface {
    $entity = $entity_type_id === NULL ? NULL : $this->getRouteMatch()->getParameter($entity_type_id);
    return $entity instanceof ContentEntityInterface ? $entity : throw new NotFoundHttpException();
  }

}
