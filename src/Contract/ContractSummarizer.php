<?php

declare(strict_types=1);

namespace Drupal\content_relay\Contract;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Describes a contract in plain language.
 *
 * Built only from the stored terms, so what it says is what is in force.
 */
class ContractSummarizer {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected LanguageManagerInterface $languageManager,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * A sentence or two describing what the replica receives.
   *
   * @param \Drupal\content_relay\Contract\SubscriptionContract $contract
   *   The contract.
   * @param string|null $replica_label
   *   The replica's name, to describe it from the origin's side. NULL to
   *   address the replica as "you".
   */
  public function summarize(SubscriptionContract $contract, ?string $replica_label = NULL): string {
    $subject = $replica_label === NULL ? (string) $this->t('You receive') : (string) $this->t('@replica receives', ['@replica' => $replica_label]);
    $bundles = $this->bundleLabels($contract->effectiveBundles());
    if ($bundles === []) {
      $sentence = (string) $this->t('@subject nothing yet: no content types are subscribed.', ['@subject' => $subject]);
    }
    else {
      $sentence = (string) $this->t('@subject @bundles in @languages, @processing, @encryption.', [
        '@subject' => $subject,
        '@bundles' => $this->listing($bundles),
        '@languages' => $this->languages($contract),
        '@processing' => $this->processing($contract),
        '@encryption' => $contract->encryption() === 'none' ? $this->t('unencrypted') : $this->t('encrypted'),
      ]);
    }

    $extra = [];
    if ($contract->isPaused()) {
      $extra[] = (string) $this->t('Delivery is paused.');
    }
    if ($limit = $contract->maxEventsPerMinute()) {
      $extra[] = (string) $this->formatPlural($limit, 'At most 1 event a minute.', 'At most @count events a minute.');
    }
    if ($windows = $contract->deliveryWindows()) {
      $extra[] = (string) $this->t('Delivered only between @windows (UTC).', [
        '@windows' => $this->listing(array_map(fn (array $w) => $w['start'] . '–' . $w['end'], $windows)),
      ]);
    }
    foreach ($contract->notGranted() as $refusal) {
      $extra[] = (string) $this->t('Requested but not granted: @value (@reason).', [
        '@value' => $this->describeValue($refusal),
        '@reason' => $refusal['reason'],
      ]);
    }
    return trim($sentence . ' ' . implode(' ', $extra));
  }

  /**
   * Bundle labels, by entity type, in a stable order.
   *
   * @return string[]
   *   Labels.
   */
  protected function bundleLabels(array $bundles): array {
    $labels = [];
    ksort($bundles);
    foreach ($bundles as $entity_type_id => $list) {
      $info = $this->bundleInfo->getBundleInfo($entity_type_id);
      sort($list);
      foreach ($list as $bundle) {
        $labels[] = (string) ($info[$bundle]['label'] ?? $entity_type_id . ':' . $bundle);
      }
    }
    return $labels;
  }

  /**
   * The languages part of the sentence.
   */
  protected function languages(SubscriptionContract $contract): string {
    $languages = $contract->terms['effective']['languages'] ?? [];
    if (($languages['mode'] ?? SubscriptionContract::LANGUAGES_ALL) !== SubscriptionContract::LANGUAGES_SELECTED) {
      $excluded = [];
      foreach ($contract->excludedLanguages() as $langcode) {
        $excluded[] = (string) ($this->languageManager->getLanguage($langcode)?->getName() ?? $langcode);
      }
      return $excluded === []
        ? (string) $this->t('all languages')
        : (string) $this->t('all languages except @languages', ['@languages' => $this->listing($excluded)]);
    }
    $names = [];
    foreach ($languages['selected'] ?? [] as $langcode) {
      $names[] = (string) ($this->languageManager->getLanguage($langcode)?->getName() ?? $langcode);
    }
    return $names === [] ? (string) $this->t('no languages') : $this->listing($names);
  }

  /**
   * The processing part of the sentence.
   */
  protected function processing(SubscriptionContract $contract): string {
    return match ($contract->terms['effective']['processing']['default'] ?? 'immediate') {
      'queued' => (string) $this->t('queued for processing'),
      'auto' => (string) $this->t('immediately when possible'),
      default => (string) $this->t('immediately'),
    };
  }

  /**
   * Joins items as "A", "A and B" or "A, B and C".
   */
  protected function listing(array $items): string {
    if (count($items) < 2) {
      return implode('', $items);
    }
    $last = array_pop($items);
    return (string) $this->t('@items and @last', ['@items' => implode(', ', $items), '@last' => $last]);
  }

  /**
   * A refused value in words.
   */
  protected function describeValue(array $refusal): string {
    if ($refusal['term'] === 'bundle' && str_contains($refusal['value'], '.')) {
      [$entity_type_id, $bundle] = explode('.', $refusal['value'], 2);
      return (string) ($this->bundleInfo->getBundleInfo($entity_type_id)[$bundle]['label'] ?? $refusal['value']);
    }
    return $refusal['value'];
  }

}
