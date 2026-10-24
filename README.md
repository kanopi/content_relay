# Content Relay

Keeps content in sync from one Drupal site (the **origin**) to any number of
others (**replicas**) without database dumps. Every change travels as a signed,
optionally encrypted event carrying a full snapshot of the entity, identified
by UUID only, with a canonical hash anyone can recompute to check a copy.

This project is being built in phases. This README describes what exists now
and grows with each phase; see `docs/decisions.md` for choices that differ from,
or refine, the original specification.

## Modules

| Module | Role | Status |
| --- | --- | --- |
| `content_relay` | Shared base: event schema, entity serialization, hashing, signing, keys | Phase 1 |
| `content_relay_origin` | Publishes changes to replicas | Not started |
| `content_relay_replica` | Receives and applies changes | Not started |

## Requirements

- Drupal 10.3 or 11, PHP 8.3 or later, with the `sodium` extension.
- The [Key](https://www.drupal.org/project/key) module.
- A `utf8mb4` database. Hashes cover text byte for byte, and a database that
  cannot store four-byte characters changes the bytes.

## Architecture (base module)

### Snapshots

`EntitySerializer` turns an entity into a **snapshot**, a plain array that
survives JSON unchanged, and back. The structure is documented on
`Serialization\Snapshot`. In short: untranslatable fields once under `shared`,
translatable fields per langcode under `translations`, and informative values
that are not content (changed timestamps) under `metadata`. References are
UUIDs; embedded entities such as paragraphs are nested snapshots.

The work is done by **normalizer plugins**, one per entity type. The generic
normalizer handles any content entity type with a UUID key. A module can
override it for its own entity type by adding a plugin in
`src/Plugin/ContentRelay/Normalizer` with a higher weight than the generic one
(-100); see the `ContentRelayNormalizer` attribute. Entity types without a UUID
key are never offered for sync.

Subscribers to `content_relay.pre_serialize` and `content_relay.post_serialize`
can adjust what is serialized.

### Hashes

`RevisionHasher` computes a SHA-256 hash of the whole snapshot and one per
translation, from canonical JSON (`Hash\CanonicalJson`): map keys sorted, lists
in order, Unicode written as stored, fixed encoding flags. The metadata section
is excluded, and a replica can exclude its local-only fields. Every hash carries
an algorithm version (`RevisionHasher::CURRENT_VERSION`) so the canonical form
can change without invalidating stored hashes.

### Messages

Everything one site says to another is a `Crypto\Message`, signed with the
sender's Ed25519 key into a `Crypto\SignedEnvelope`. The signature covers the
message ID, type, sender, recipient, timestamp, sequence, encryption mode and
body. `Crypto\MessageVerifier` refuses malformed envelopes, unknown or retired
keys, bad signatures, envelopes whose headers disagree with the signed message,
messages for another site, timestamps outside the clock window (default five
minutes) and replays.

Sync events (`Event\SyncEvent`) are message bodies with their own versioned
schema (`v: 1`).

### Keys

Each site has a site ID (a UUID generated on install) and key pairs for
signing (Ed25519) and, later, encryption (X25519). Private keys are stored in
Key module entities using the **file** or **environment variable** provider;
the configuration and state providers are refused so a private key never
appears in a config export or the database. `content_relay.settings` lists the
key entities with a status: `primary` signs new messages, `active` is still
accepted during a rotation, `retired` is refused. Key IDs are derived from the
public key.

Generating keys and the admin UI for them arrive in later phases. Until then:

```php
[$pair, $material] = \Drupal\content_relay\Key\KeyPair::generate(\Drupal\content_relay\Key\KeyPurpose::Signing);
// Store $material in a file outside the web root or an environment variable,
// point a Key entity at it, and list that entity in content_relay.settings.
```

## Development

From the module directory:

```bash
../../../../vendor/bin/phpcs
../../../../vendor/bin/phpstan analyse . --memory-limit=1G
../../../../vendor/bin/phpunit -c phpunit.xml.dist
```
