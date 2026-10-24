<?php

declare(strict_types=1);

namespace Drupal\Tests\content_relay_origin\Kernel;

use Drupal\content_relay\Contract\SubscriptionContract;
use Drupal\content_relay\Crypto\MessageSigner;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;
use Drupal\content_relay_origin\PairingCodes;
use Drupal\content_relay_origin\Replica;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests pairing codes and the pairing endpoint.
 *
 * @group content_relay
 */
#[Group('content_relay')]
#[RunTestsInSeparateProcesses]
final class PairingTest extends OriginTestBase {

  /**
   * A valid code pairs, exchanges keys and proposes contract version 1.
   */
  public function testValidPairing(): void {
    $body = $this->pair();
    $this->assertSame('paired', $body['result']);
    $this->assertSame($this->container->get('content_relay.site_identity')->getSiteId(), $body['origin']['site_id']);
    $this->assertSame(base64_encode($this->originKey->publicKey), $body['origin']['signing_keys'][$this->originKey->id()]['public_key']);

    // The contract is signed by the origin and addressed to the replica.
    $envelope = $body['contract'];
    $this->assertTrue(sodium_crypto_sign_verify_detached(
      base64_decode($envelope['signature']),
      MessageSigner::signedBytes($envelope['signing_key_id'], $envelope['message']),
      $this->originKey->publicKey,
    ));
    $this->assertSame([$this->replica->siteId, 'contract', 1], [
      $envelope['message']['recipient'],
      $envelope['message']['type'],
      $envelope['message']['body']['version'],
    ]);
    $this->assertSame(['node' => ['article']], $envelope['message']['body']['effective']['bundles']);

    $replica = $this->container->get('content_relay_origin.replica_registry')->get($this->replica->siteId);
    $this->assertSame([Replica::PENDING, 0, FakeReplica::ENDPOINT], [
      $replica->status,
      $replica->contractVersion,
      $replica->endpoint,
    ]);
    $this->assertSame(base64_encode($this->replica->key->publicKey), $replica->signingKeys[$this->replica->key->id()]['public_key']);
  }

  /**
   * Nothing is sent until the replica acknowledges its first contract.
   */
  public function testNoEventsBeforeAcknowledgement(): void {
    $this->pair();
    $this->replica->dropAcks = TRUE;
    $this->node();
    $this->deliver();
    $this->assertSame([], $this->replica->events);
    $this->assertSame([1], $this->replica->contracts, 'The pending contract was pushed instead.');

    $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
    $replica = $this->container->get('content_relay_origin.replica_registry')->get($this->replica->siteId);
    $this->assertSame([Replica::ACTIVE, 1], [$replica->status, $replica->contractVersion]);
    $this->deliver();
    $this->assertCount(1, $this->replica->events);
    $this->assertSame(1, $this->replica->events[0]['contract_version']);
  }

  /**
   * A lost acknowledgement is recovered by pushing the contract again.
   */
  public function testPushedContractIsAcknowledgedInTheResponse(): void {
    $this->pair();
    $this->node();
    $this->deliver();
    $this->assertSame([1], $this->replica->contracts);
    $this->assertSame(Replica::ACTIVE, $this->container->get('content_relay_origin.replica_registry')->get($this->replica->siteId)->status);
    $this->assertCount(1, $this->replica->events);
  }

  /**
   * Codes: length, format, single use, expiry, normalized input.
   */
  public function testCodes(): void {
    $codes = $this->container->get('content_relay_origin.pairing_codes');
    $this->config('content_relay_origin.settings')->set('pairing.code_length', 20)->save();
    $code = $codes->generate(NULL, 1)['code'];
    $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){4}$/', $code);
    $this->assertSame(20, strlen(PairingCodes::normalize($code)));

    // Dashes, spaces, case and look-alike letters do not matter.
    $typed = strtolower(str_replace('-', ' ', $code));
    $typed = strtr($typed, ['0' => 'o', '1' => 'l']);
    $this->assertSame(['allowed_bundles' => NULL], $codes->consume($typed, 'r1'));

    $this->expectExceptionMessage('already been used');
    $codes->consume($code, 'r2');
  }

  /**
   * An expired code is refused.
   */
  public function testExpiredCode(): void {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $this->container->get('database')->update(PairingCodes::TABLE)->fields(['expires' => time() - 1])->execute();
    $response = $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code));
    $this->assertSame([403, 'code_expired'], [$response->getStatusCode(), $this->json($response)['result']]);
    $this->assertNull($this->container->get('content_relay_origin.replica_registry')->get($this->replica->siteId));
  }

  /**
   * A used code is refused, and a paired replica cannot pair again.
   */
  public function testReusedCode(): void {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $this->assertSame(200, $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code))->getStatusCode());
    $other = new FakeReplica('x');
    $response = $this->post('/content-relay/v1/pair', $other->pairingRequest($code));
    $this->assertSame([403, 'code_used'], [$response->getStatusCode(), $this->json($response)['result']]);
  }

  /**
   * Codes are stored only as hashes.
   */
  public function testCodesAreHashed(): void {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $stored = json_encode($this->container->get('database')->select(PairingCodes::TABLE, 'c')->fields('c')->execute()->fetchAll());
    $this->assertStringNotContainsString(PairingCodes::normalize($code), $stored);
  }

  /**
   * Repeated failures from one address are blocked, even with a valid code.
   */
  public function testFloodLimit(): void {
    $this->config('content_relay_origin.settings')->set('pairing.flood_limit', 3)->save();
    for ($i = 0; $i < 3; $i++) {
      $this->assertSame(403, $this->post('/content-relay/v1/pair', $this->replica->pairingRequest('WRONG-CODE'), '203.0.113.9')->getStatusCode());
    }
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $response = $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code), '203.0.113.9');
    $this->assertSame([429, 'flood'], [$response->getStatusCode(), $this->json($response)['result']]);
    // Another address is unaffected.
    $this->assertSame(200, $this->post('/content-relay/v1/pair', $this->replica->pairingRequest($code), '198.51.100.7')->getStatusCode());
  }

  /**
   * Plain HTTP endpoints are refused.
   */
  public function testHttpEndpointIsRefused(): void {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $request = $this->replica->pairingRequest($code, ['node' => ['article']], ['webhook_url' => 'http://replica.example.com/content-relay/v1/events']);
    $response = $this->post('/content-relay/v1/pair', $request);
    $this->assertSame([400, 'insecure_url'], [$response->getStatusCode(), $this->json($response)['result']]);
  }

  /**
   * A request signed with a key other than the one offered is refused.
   */
  public function testSignatureMustMatchOfferedKey(): void {
    $code = $this->container->get('content_relay_origin.pairing_codes')->generate(NULL, 1)['code'];
    $request = $this->replica->pairingRequest($code);
    $request['label'] = 'Tampered';
    $this->assertSame(400, $this->post('/content-relay/v1/pair', $request)->getStatusCode());

    [$stranger] = KeyPair::generate(KeyPurpose::Signing);
    $request = $this->replica->pairingRequest($code);
    $request['signature'] = base64_encode($stranger->sign('anything'));
    $this->assertSame(400, $this->post('/content-relay/v1/pair', $request)->getStatusCode());
    $this->assertNull($this->container->get('content_relay_origin.replica_registry')->get($this->replica->siteId));
  }

  /**
   * A code limited to some bundles grants only those.
   */
  public function testCodeLimitsBundles(): void {
    $body = $this->pair(['node' => ['article']], ['node' => ['article', 'page']]);
    $terms = $body['contract']['message']['body'];
    $this->assertSame(['node' => ['article']], $terms['effective']['bundles']);
    $this->assertSame([['term' => 'bundle', 'value' => 'node.page', 'reason' => 'Not allowed for this replica.']], $terms['not_granted']);

    $this->post('/content-relay/v1/messages', $this->replica->message('contract_ack', ['version' => 1]));
    $this->node('page');
    $this->node('article');
    $this->deliver();
    $this->assertSame(['article'], array_column($this->replica->events, 'bundle'));
  }

  /**
   * With approval required, events wait for an administrator.
   */
  public function testManualApproval(): void {
    $this->config('content_relay_origin.settings')->set('pairing.require_approval', TRUE)->save();
    $this->pairAndAcknowledge();
    $registry = $this->container->get('content_relay_origin.replica_registry');
    $this->assertSame(Replica::PENDING, $registry->get($this->replica->siteId)->status);
    $this->node();
    $this->deliver();
    $this->assertSame([], $this->replica->events);

    $registry->setApproved($this->replica->siteId, TRUE);
    $this->container->get('content_relay_origin.contract_manager')->activateIfReady($this->replica->siteId);
    $this->assertSame(Replica::ACTIVE, $registry->get($this->replica->siteId)->status);
    $this->deliver();
    $this->assertCount(1, $this->replica->events);
  }

  /**
   * A replica's requested terms default sensibly.
   */
  public function testDefaultTerms(): void {
    $terms = $this->pair()['contract']['message']['body'];
    $this->assertSame(SubscriptionContract::LANGUAGES_ALL, $terms['effective']['languages']['mode']);
    $this->assertSame('immediate', $terms['effective']['processing']['default']);
    $this->assertSame(FakeReplica::ENDPOINT, $terms['replica_terms']['webhook_url']);
  }

}
