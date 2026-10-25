<?php

declare(strict_types=1);

namespace Drupal\content_relay_replica\Form;

use Drupal\content_relay_replica\Apply\ApplyResult;
use Drupal\content_relay_replica\Conflicts;
use Drupal\content_relay_replica\LocalChanges;
use Drupal\content_relay_replica\Puller;
use Drupal\content_relay_replica\SubscriptionException;
use Drupal\content_relay_replica\SyncStateStore;
use Drupal\content_relay_replica\SyncStatus;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pulls the latest version of one item, or resolves its conflict.
 *
 * Without local edits this pulls straight away. With them, or when the item
 * is already in conflict, it shows what differs and lets the user take the
 * origin's version or keep the local one, which detaches the item.
 */
final class ItemPullForm extends FormBase {

  public function __construct(
    protected Puller $puller,
    protected Conflicts $conflicts,
    protected SyncStateStore $syncState,
    protected LocalChanges $localChanges,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('content_relay_replica.puller'),
      $container->get('content_relay_replica.conflicts'),
      $container->get('content_relay_replica.sync_state'),
      $container->get('content_relay_replica.local_changes'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_relay_replica_item_pull';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL, ?string $uuid = NULL): array {
    $state = $entity_type_id === NULL || $uuid === NULL ? NULL : $this->syncState->get($entity_type_id, $uuid);
    if ($state === NULL) {
      throw new NotFoundHttpException();
    }
    $entity = $this->localChanges->localCopy($entity_type_id, $uuid, $state);
    $label = $entity === NULL ? $uuid : (string) $entity->label();
    $form_state->set('item', [$entity_type_id, $uuid, $label]);
    $back = Url::fromRoute('view.content_relay_replica_report.page_1');

    if ($state['detached_at'] !== NULL) {
      $form['#title'] = $this->t('Pull %label', ['%label' => $label]);
      $form['detached'] = [
        '#markup' => '<p>' . $this->t('This item is detached on this site, so it is not updated from the origin. <a href=":url">Reattach it</a> to take the origin\'s version.', [
          ':url' => Url::fromRoute('content_relay_replica.reattach', [
            'entity_type_id' => $entity_type_id,
            'uuid' => $uuid,
          ])->toString(),
        ]) . '</p>',
      ];
      return $form;
    }

    $edited = $entity === NULL ? NULL : $this->localChanges->editedTranslations($entity, $state);
    $conflict = $state['status'] === SyncStatus::CONFLICT || $edited !== NULL;
    $form['actions'] = ['#type' => 'actions'];
    if (!$conflict) {
      $form['#title'] = $this->t('Pull %label', ['%label' => $label]);
      $form['intro'] = ['#markup' => '<p>' . $this->t("Fetch this item's latest version from the origin and apply it here.") . '</p>'];
      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => $this->t('Pull latest from origin'),
        '#name' => 'pull',
      ];
      $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Cancel'), '#url' => $back];
      return $form;
    }

    $form['#title'] = $this->t('Resolve %label', ['%label' => $label]);
    $form['intro'] = [
      '#markup' => '<p>' . ($state['conflict_revision'] !== NULL
        ? $this->t("This item was edited here before the origin's version arrived. The origin's version was saved, and your edit kept in an earlier revision. Keep the origin's version, or restore your edit and detach the item so the origin stops replacing it.")
        : $this->t("This item was edited here, so the origin's version is not applied without your say. Take the origin's version, replacing your edits, or keep your edits and detach the item so the origin stops replacing it.")) . '</p>',
    ];
    try {
      $form['diff'] = self::diffTable($this->conflicts->diff($entity_type_id, $uuid));
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addWarning($this->t('The origin version could not be fetched to compare: @message', ['@message' => $e->getMessage()]));
    }

    $resolve = $this->currentUser()->hasPermission('resolve content relay conflicts');
    $form['actions']['accept'] = [
      '#type' => 'submit',
      '#value' => $this->t('Accept origin version'),
      '#name' => 'accept',
      '#access' => $resolve,
    ];
    $form['actions']['keep'] = [
      '#type' => 'submit',
      '#value' => $this->t('Keep local and detach'),
      '#name' => 'keep',
      '#access' => $resolve,
    ];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Cancel'), '#url' => $back];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    [$entity_type_id, $uuid, $label] = $form_state->get('item');
    $action = $form_state->getTriggeringElement()['#name'] ?? '';
    $form_state->setRedirect('view.content_relay_replica_report.page_1');
    if ($action === 'keep') {
      $this->conflicts->keepLocal($entity_type_id, $uuid);
      $this->messenger()->addStatus($this->t('%label keeps its local version and is detached.', ['%label' => $label]));
      return;
    }
    try {
      $result = $action === 'accept'
        ? $this->conflicts->acceptOrigin($entity_type_id, $uuid)
        : $this->puller->pull($entity_type_id, $uuid);
    }
    catch (SubscriptionException $e) {
      $this->messenger()->addError($this->t('The origin could not be asked: @message', ['@message' => $e->getMessage()]));
      return;
    }
    if ($result === NULL) {
      $this->messenger()->addStatus($this->t("%label keeps the origin's version; the conflict is resolved.", ['%label' => $label]));
      return;
    }
    $message = self::describe($result);
    $result->httpStatus >= 400 ? $this->messenger()->addError($message) : $this->messenger()->addStatus($message);
    if ($result->result === ApplyResult::CONFLICT) {
      $form_state->setRedirect('content_relay_replica.pull', ['entity_type_id' => $entity_type_id, 'uuid' => $uuid]);
    }
  }

  /**
   * A table of what differs, from Conflicts::diff().
   */
  public static function diffTable(array $diff): array {
    $table = [
      '#type' => 'table',
      '#caption' => new TranslatableMarkup('What differs'),
      '#header' => [
        new TranslatableMarkup('Field'),
        new TranslatableMarkup('Language'),
        $diff['local'],
        $diff['origin'],
      ],
      '#empty' => new TranslatableMarkup('Only the set of translations differs.'),
    ];
    foreach ($diff['rows'] as $i => $row) {
      $table[$i] = [
        'field' => ['#plain_text' => $row['field']],
        'langcode' => ['#plain_text' => $row['langcode'] === '' ? new TranslatableMarkup('All') : $row['langcode']],
        'local' => ['#type' => 'html_tag', '#tag' => 'pre', 'text' => ['#plain_text' => $row['local']]],
        'origin' => ['#type' => 'html_tag', '#tag' => 'pre', 'text' => ['#plain_text' => $row['origin']]],
      ];
    }
    return $table;
  }

  /**
   * A sentence for the result of a pull.
   */
  public static function describe(ApplyResult $result): TranslatableMarkup {
    return match ($result->result) {
      ApplyResult::APPLIED => new TranslatableMarkup("The origin's latest version was applied."),
      ApplyResult::UNCHANGED => new TranslatableMarkup('Already up to date.'),
      ApplyResult::DELETED => new TranslatableMarkup('The item was deleted, as on the origin.'),
      ApplyResult::CONFLICT => new TranslatableMarkup("The item was edited here, so the origin's version was not applied. Resolve the conflict to choose."),
      ApplyResult::SKIPPED_DETACHED => new TranslatableMarkup('The item is detached here and was not updated.'),
      ApplyResult::ACCEPTED => new TranslatableMarkup('The item will be applied once what it needs arrives: @message', ['@message' => (string) $result->message]),
      default => new TranslatableMarkup('The item could not be applied (@result): @message', [
        '@result' => $result->code ?? $result->result,
        '@message' => (string) $result->message,
      ]),
    };
  }

}
