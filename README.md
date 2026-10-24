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
| `content_relay_origin` | Publishes changes to replicas | Phase 2: hooks, event log, delivery, hash store |
| `content_relay_replica` | Receives and applies changes | Phase 2: endpoint, transactional apply, failures |

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
signing (Ed25519) and encryption (X25519). Keys are ordinary
[Key](https://www.drupal.org/project/key) module entities of two key types this
module provides: **Content Relay signing key** and **Content Relay encryption
key**. Content Relay finds them by type; there is no list of keys in its own
settings.

Each key's status is set on its edit form, under the key type settings:

- **Primary** signs new messages (one per type);
- **Active** is still accepted, for the overlap during a rotation;
- **Retired** is never used, and its value is not even read.

Private keys must use the **file** or **environment variable** provider. The
configuration and state providers are refused, on the key form and at
runtime, so a private key never appears in a config export or the database.
Key IDs used in messages are derived from the public key.

To create a key, generate its value and store it where the provider will read
it, then add the key entity pointing at it:

```php
[, $value] = \Drupal\content_relay\Key\KeyPair::generate(\Drupal\content_relay\Key\KeyPurpose::Signing);
file_put_contents('/path/outside/webroot/content_relay_signing.key', $value);
```

A Drush command for this arrives with the admin tooling.

## Origin

Bundles to publish are listed in `content_relay_origin.settings:bundles`, keyed
by entity type. Saving or deleting an entity in one of them stores its hashes
(`content_relay_revision_hash`) and adds one row per replica to the event log
(`content_relay_outbox`), inside the save transaction. Nothing is sent during
the save.

The `content_relay_origin_delivery` queue sends each replica its rows in
sequence order. The snapshot is built when a row is sent, from the entity as
it is then, so a retry or replay never sends an outdated version, and several
queued updates of one entity go out as one event. Retries use exponential
backoff with jitter (`delivery.*` settings); cron queues retries that came
due and prunes acknowledged rows after `retention_days`.

| Replica answers | Event log status | Retried? |
| --- | --- | --- |
| 200 | applied | no |
| 202 | accepted | no; confirmed later |
| 409 | stale | no |
| 422 `apply_failed` | failed_on_replica | no; the replica owns retries |
| other 4xx | dead_letter | no; replay by hand |
| 408, 429, 5xx, timeout | failed, then dead_letter | yes, up to `max_attempts` |

`content_relay_origin.replay` replays a dead-lettered or failed row as a new
event decided from the entity as it is now.

Replicas are stored in `content_relay_replica` (pairing arrives in phase 3).

## Replica

Events arrive at `POST /content-relay/v1/events` as signed envelopes. The
endpoint verifies the signature and that the sender is the paired origin,
records the sequence (and any gap), and hands the event to
`content_relay_replica.applier`, which every way of applying an event goes
through. Its rules, in order:

1. An event already handled gets the same answer again.
2. A snapshot that does not match its signed hash is refused (400).
3. A per-UUID lock is held while applying.
4. A local item with the same UUID but no sync state is never touched.
5. Events older than what is here are refused as stale (409).
6. The entity, its translations and the sync state row are written in one
   transaction, as a new revision logged "Synced from origin …". Any failure
   rolls everything back.
7. The saved copy is hashed again and compared with the origin's hash.

Failures are kept in `content_relay_failed_event` with the full event:

- **transient** (deadlocks, lock timeouts, lost connections, or a
  `TransientApplyException`): answered 202 and retried locally by cron and
  the `content_relay_replica_retry` queue, then treated as permanent;
- **permanent** (validation, exceptions in hooks, UUID collisions): answered
  422 `apply_failed` with a short summary that never includes exception
  messages or paths;
- **dependency** (references to items not here yet): answered 202, and retried
  automatically when the missing item arrives.

A newer event that applies supersedes older failures. Subscribers to
`content_relay.apply_failed` can send alerts, and the status report shows a
warning while failures are open.

The paired origin is stored in state (`content_relay_replica.origin`); pairing
arrives in phase 3.

## Development

From the module directory:

```bash
../../../../vendor/bin/phpcs
../../../../vendor/bin/phpstan analyse . --memory-limit=1G
../../../../vendor/bin/phpunit -c phpunit.xml.dist
```
