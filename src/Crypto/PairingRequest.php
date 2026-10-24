<?php

declare(strict_types=1);

namespace Drupal\content_relay\Crypto;

use Drupal\content_relay\Hash\CanonicalJson;
use Drupal\content_relay\Key\KeyPair;
use Drupal\content_relay\Key\KeyPurpose;

/**
 * The request a replica sends to pair, signed with the key it offers.
 *
 * Before pairing the origin has no key for the replica, so an ordinary signed
 * message cannot be checked. The request is instead signed with the signing
 * key it carries. That proves the replica holds the private half of the key it
 * is registering; the pairing code proves the replica was invited.
 */
final class PairingRequest {

  public const CONTEXT = "content_relay.pairing.v1\n";

  /**
   * Signs a pairing request body.
   *
   * @param array $body
   *   The request, including signing_keys with the signing key's public key.
   * @param \Drupal\content_relay\Key\KeyPair $key
   *   The replica's primary signing key.
   *
   * @return array
   *   The body with signing_key_id and signature added.
   */
  public static function sign(array $body, KeyPair $key): array {
    $body['signing_key_id'] = $key->id();
    $body['signature'] = base64_encode($key->sign(self::CONTEXT . CanonicalJson::encode(array_diff_key($body, ['signature' => TRUE]))));
    return $body;
  }

  /**
   * Checks a pairing request was signed by the key it names.
   *
   * @return array
   *   The request without its signature.
   *
   * @throws \Drupal\content_relay\Crypto\VerificationException
   *   When the request is malformed or the signature does not verify.
   */
  public static function verify(array $request): array {
    $key_id = $request['signing_key_id'] ?? NULL;
    $signature = is_string($request['signature'] ?? NULL) ? base64_decode($request['signature'], TRUE) : FALSE;
    $public = is_string($key_id) ? ($request['signing_keys'][$key_id]['public_key'] ?? NULL) : NULL;
    $public = is_string($public) ? base64_decode($public, TRUE) : FALSE;
    if ($signature === FALSE || $public === FALSE || strlen($public) !== \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== \SODIUM_CRYPTO_SIGN_BYTES) {
      throw new VerificationException(VerificationException::MALFORMED, 'The pairing request has no usable key or signature.');
    }
    // The key ID must be the one derived from the key, or a replica could
    // register a key under an ID that collides with another.
    if (KeyPair::idFor(KeyPurpose::Signing, $public) !== $key_id) {
      throw new VerificationException(VerificationException::MALFORMED, 'The signing key ID does not match the key.');
    }
    $body = array_diff_key($request, ['signature' => TRUE]);
    try {
      $bytes = self::CONTEXT . CanonicalJson::encode($body);
    }
    catch (\JsonException | \InvalidArgumentException $e) {
      throw new VerificationException(VerificationException::MALFORMED, 'The pairing request cannot be encoded canonically.', $e);
    }
    if (!sodium_crypto_sign_verify_detached($signature, $bytes, $public)) {
      throw new VerificationException(VerificationException::BAD_SIGNATURE, 'The pairing request signature does not verify.');
    }
    return $body;
  }

}
