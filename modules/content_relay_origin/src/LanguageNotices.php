<?php

declare(strict_types=1);

namespace Drupal\content_relay_origin;

use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Tells replicas when this site adds or removes a language.
 *
 * A replica receiving every language starts getting a new one by itself; one
 * receiving selected languages shows it as pending until someone there adds
 * or ignores it. A removed language's translations stay on the replica as
 * local translations unless someone there deletes them.
 *
 * Notices are signed language_notice messages to the replica's endpoint. Each
 * is kept per replica until acknowledged, and sent again on cron.
 */
class LanguageNotices {

  /**
   * State key: notices not yet acknowledged, by replica.
   */
  public const STATE = 'content_relay_origin.language_notices';

  public function __construct(
    protected ReplicaRegistry $registry,
    protected MessageSigner $signer,
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected SendingSettings $sending,
  ) {}

  /**
   * Records a language change for every replica, and sends it.
   *
   * @param string $change
   *   What changed: added or removed.
   * @param string $langcode
   *   The langcode.
   * @param string $name
   *   The language's name.
   */
  public function notify(string $change, string $langcode, string $name): void {
    $pending = $this->state->get(self::STATE, []);
    foreach ($this->registry->getAll() as $replica) {
      // A language never sent to a replica is no news to it.
      $excluded = (array) ($this->sending->get('languages.excluded', $replica->id) ?? []);
      if ($replica->receivesEvents() && !in_array($langcode, $excluded, TRUE)) {
        $pending[$replica->id][] = ['change' => $change, 'langcode' => $langcode, 'name' => $name];
      }
    }
    $this->state->set(self::STATE, $pending);
    $this->logger->notice('Language @langcode was @change; replicas are told.', [
      '@langcode' => $langcode,
      '@change' => $change,
    ]);
    $this->sendPending();
  }

  /**
   * Sends every notice not yet acknowledged.
   */
  public function sendPending(): void {
    $pending = $this->state->get(self::STATE, []);
    foreach ($pending as $replica_id => $notices) {
      $replica = $this->registry->get($replica_id);
      if ($replica === NULL || !$replica->receivesEvents()) {
        unset($pending[$replica_id]);
        continue;
      }
      try {
        $response = $this->httpClient->request('POST', $replica->endpoint, [
          'json' => $this->signer->sign($replica_id, 'language_notice', ['changes' => $notices])->toArray(),
          'headers' => ['Accept' => 'application/json'],
          'timeout' => 30,
          'http_errors' => FALSE,
          'allow_redirects' => FALSE,
        ]);
      }
      catch (GuzzleException $e) {
        $this->logger->warning('Replica @replica could not be told about language changes: @message', [
          '@replica' => $replica_id,
          '@message' => $e->getMessage(),
        ]);
        continue;
      }
      $body = json_decode((string) $response->getBody(), TRUE);
      if ($response->getStatusCode() === 200 && ($body['result'] ?? NULL) === 'acknowledged') {
        unset($pending[$replica_id]);
      }
    }
    $this->state->set(self::STATE, $pending);
  }

}
