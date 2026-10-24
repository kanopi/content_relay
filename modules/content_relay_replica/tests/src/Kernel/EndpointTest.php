<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_replica\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\content_relay\Crypto\EncryptionMode;
use Drupal\content_relay\Crypto\Message;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Event\Operation;
use Drupal\content_relay\Event\SyncEvent;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the receiving endpoint through Drupal's HTTP kernel.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class EndpointTest extends ReplicaTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Signs an event as the origin and returns the envelope.
   */
  private function envelope(SyncEvent $event, ?KeyPair $key = NULL, string $sender = self::ORIGIN, string $type = 'event'): array {
    $message = new Message((new PhpUuid())->generate(), $type, $sender, $this->container->get('content_relay.site_identity')->getSiteId(), time(), EncryptionMode::None, $event->toArray(), $event->sequence);
    return (new MessageSigner(
      $this->container->get('content_relay.key_store'),
      $this->container->get('content_relay.site_identity'),
      $this->container->get('datetime.time'),
      $this->container->get('uuid'),
    ))->signMessage($message, $key ?? $this->originKey)->toArray();
  }

  /**
   * Posts a body to the endpoint.
   */
  private function post(array|string $body): Response {
    $request = Request::create('/content-relay/v1/events', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], is_string($body) ? $body : json_encode($body));
    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * A signed event is applied and answered.
   */
  public function testSignedEventIsApplied(): void {
    $snapshot = $this->originArticle('Over the wire');
    $event = $this->event($snapshot, Operation::Create, 1);
    $response = $this->post($this->envelope($event));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(
      ['result' => 'applied', 'event_id' => $event->eventId],
      json_decode((string) $response->getContent(), TRUE),
    );
    $this->assertSame('Over the wire', $this->local($snapshot['uuid'])->label());
    $this->assertSame(1, $this->container->get('content_relay_replica.stream')->getLastSequence(self::ORIGIN));

    // The identical request again is a replay of the message.
    $again = $this->post($this->envelope($event));
    $this->assertSame(200, $again->getStatusCode(), 'A new message carrying the same event gets the earlier answer.');
  }

  /**
   * Refusals before anything is applied.
   */
  public function testRefusals(): void {
    $snapshot = $this->originArticle();
    $event = $this->event($snapshot, Operation::Create, 1);

    $this->assertSame(400, $this->post('not json')->getStatusCode());

    $tampered = $this->envelope($event);
    $tampered['message']['body']['snapshot']['shared']['title'][0]['value'] = 'Tampered';
    $response = $this->post($tampered);
    $this->assertSame(
      [403, 'bad_signature'],
      [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)['result']],
    );

    [$stranger] = KeyPair::generate(KeyPurpose::Signing);
    $this->assertSame(403, $this->post($this->envelope($event, $stranger))->getStatusCode());
    $this->assertSame(403, $this->post($this->envelope($event, NULL, 'someone-else'))->getStatusCode());
    $this->assertSame(400, $this->post($this->envelope($event, NULL, self::ORIGIN, 'contract'))->getStatusCode());

    $replayed = $this->envelope($event);
    $this->assertSame(200, $this->post($replayed)->getStatusCode());
    $response = $this->post($replayed);
    $this->assertSame(
      [403, 'replayed'],
      [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE)['result']],
    );
  }

  /**
   * A skipped predecessor is recorded as a gap.
   */
  public function testGapIsRecorded(): void {
    $first = $this->event($this->originArticle('One'), Operation::Create, 1);
    $this->event($this->originArticle('Lost'), Operation::Create, 1);
    $third = $this->event($this->originArticle('Three'), Operation::Create, 1);
    $this->post($this->envelope($first));
    $this->post($this->envelope($third));
    $this->assertSame([[1, 2]], $this->container->get('content_relay_replica.stream')->getGaps(self::ORIGIN));
  }

}
