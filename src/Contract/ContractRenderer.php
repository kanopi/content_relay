<?php

declare(strict_types=1);

namespace Drupal\content_relay\Contract;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Renders a contract and its history the same way on both sites.
 */
class ContractRenderer {

  use StringTranslationTrait;

  public function __construct(
    protected ContractSummarizer $summarizer,
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * The current contract: summary, version and since when.
   *
   * @param \Drupal\content_relay\Contract\SubscriptionContract|null $active
   *   The version in force.
   * @param \Drupal\content_relay\Contract\SubscriptionContract|null $pending
   *   A newer version waiting for acknowledgement.
   * @param int|null $since
   *   When the active version took effect.
   * @param string|null $replica_label
   *   The replica's name, when shown on the origin.
   * @param int|null $from
   *   The first event sequence it applied to, when known.
   */
  public function current(?SubscriptionContract $active, ?SubscriptionContract $pending, ?int $since, ?string $replica_label = NULL, ?int $from = NULL): array {
    $build = [];
    if ($active === NULL) {
      $build['none'] = ['#markup' => '<p>' . $this->t('No contract is in force yet.') . '</p>'];
    }
    else {
      $build['summary'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->summarizer->summarize($active, $replica_label),
        '#attributes' => ['class' => ['content-relay-contract-summary']],
      ];
      $build['version'] = [
        '#type' => 'item',
        '#title' => $this->t('Version'),
        '#markup' => $since
          ? $this->t('@version, in force since @date, from event @sequence', [
            '@version' => $active->version(),
            '@date' => $this->dateFormatter->format($since, 'short'),
            '@sequence' => $from ?? $active->effectiveFromSequence(),
          ])
          : (string) $active->version(),
      ];
    }
    if ($pending !== NULL) {
      $build['pending'] = [
        '#type' => 'item',
        '#title' => $this->t('Waiting for acknowledgement'),
        '#markup' => $this->t('Version @version: @summary The previous version stays in force until it is acknowledged.', [
          '@version' => $pending->version(),
          '@summary' => $this->summarizer->summarize($pending, $replica_label),
        ]),
      ];
    }
    return $build;
  }

  /**
   * A table of every version, with what changed.
   *
   * @param array<int, array{contract: \Drupal\content_relay\Contract\SubscriptionContract, status: string, created: int, created_by: int|null, initiated_by: string}> $history
   *   Versions, newest first.
   */
  public function history(array $history): array {
    $rows = [];
    $versions = array_values($history);
    foreach ($versions as $i => $entry) {
      $previous = $versions[$i + 1]['contract'] ?? NULL;
      $changes = [];
      foreach ($entry['contract']->diff($previous) as $change) {
        $changes[] = $this->t('@path: @old → @new', [
          '@path' => $change['path'],
          '@old' => $this->value($change['old']),
          '@new' => $this->value($change['new']),
        ]);
      }
      $rows[] = [
        $entry['contract']->version(),
        $entry['status'],
        $this->dateFormatter->format($entry['created'], 'short'),
        $this->who($entry),
        [
          'data' => [
            '#theme' => 'item_list',
            '#items' => $previous === NULL ? [$this->t('First version')] : ($changes ?: [$this->t('No changes to the terms')]),
          ],
        ],
      ];
    }
    return [
      '#type' => 'table',
      '#caption' => $this->t('History'),
      '#header' => [
        $this->t('Version'),
        $this->t('Status'),
        $this->t('Created'),
        $this->t('Changed by'),
        $this->t('Changes'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No versions yet.'),
      '#attributes' => ['class' => ['content-relay-contract-history']],
    ];
  }

  /**
   * Who made a version.
   */
  protected function who(array $entry): string {
    $side = $entry['initiated_by'] === 'origin' ? $this->t('origin') : $this->t('replica');
    if (empty($entry['created_by'])) {
      return (string) $side;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($entry['created_by']);
    return (string) $this->t('@user (@side)', [
      '@user' => $user?->label() ?? '#' . $entry['created_by'],
      '@side' => $side,
    ]);
  }

  /**
   * A term value in words.
   */
  protected function value(mixed $value): string {
    return match (TRUE) {
      $value === NULL => '—',
      is_bool($value) => $value ? 'yes' : 'no',
      is_array($value) => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '',
      default => (string) $value,
    };
  }

}
