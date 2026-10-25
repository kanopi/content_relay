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
| `content_relay_origin` | Publishes changes to replicas | Phases 2–7: event log, delivery, hash store, pairing, contracts, not syncable, push, status report, files, encryption |
| `content_relay_replica` | Receives and applies changes | Phases 2–7: endpoint, transactional apply, failures, pairing, contracts, detach, files, encryption |

## Requirements

- Drupal 10.3 or 11, PHP 8.3 or later, with the `sodium` extension.
- The [Key](https://www.drupal.org/project/key) module, and File and Views
  (core).
- `lcobucci/jwt` 5.5 or later (installed by Composer).
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

Any content entity type with a UUID and storage can be synced; nothing lists
the supported types. Two kinds of plugin do the work:

- **Field normalizers** handle a kind of field wherever it appears, on every
  entity type. Built in:
  - *entity references* of any kind (entity reference, file, image, comment
    hosts, and contrib types like Dynamic Entity Reference whose target type
    is per item): content targets travel as UUIDs, configuration targets by
    machine name;
  - *embedded entities*: an Entity Reference Revisions field whose target is
    composite (paragraphs, or any type declaring the parent keys) carries the
    target's whole snapshot, to any depth;
  - *links* and *system paths* (link fields, a path alias's path): local IDs in
    `entity:node/5`, `internal:/node/5/edit`, `route:` URIs and `/node/5`
    become UUID tokens, using every entity type's link templates;
  - *menu link parents*, so a child waits for its parent;
  - *moderation state*, synced per translation although it is computed.
- **Entity normalizers** handle what is particular to one entity type. The
  generic one covers everything; users (no password, activity or, by default,
  roles) and comments (no thread or IP address) have their own.

A module adds either by placing a plugin in
`src/Plugin/ContentRelay/FieldNormalizer` or `src/Plugin/ContentRelay/Normalizer`
with a higher weight than the built-in one. Entity types without a UUID key,
and types that are never stored (contact messages), are never offered.

String entity IDs, which are chosen rather than generated, travel with the
snapshot; integer IDs never do.

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

### Encryption

Messages are signed, then optionally sealed: the signed message and its
signature are encrypted together to the recipient's X25519 key with
libsodium's sealed boxes, so in transit only the sender, the key IDs and the
mode are visible. The mode is inside the signed message too, so a sealed
message cannot be passed on unsealed, nor the other way round. HTTPS is
required either way.

Whether messages are sealed is part of the subscription contract. The
replica asks for it on its Contract page ("Encrypt messages and files"), and
it is granted when both sites have an encryption key. Each site can also
**require encryption** (origin settings, or the replica's Contract page;
`content_relay.settings:encryption.require`): it then refuses unencrypted
messages from every peer however they are signed, and every new contract is
encrypted.

Downgrade protection: unencrypted messages from a peer are accepted only
while the contract in force with it (or, on the origin, the one awaiting
acknowledgement) is unencrypted, and for `encryption.transition_window`
seconds (default an hour) after a contract switched encryption on, for
messages already on their way. Encrypted messages are always accepted. Every
mode change is logged with the contract version and the user.

Files follow the same setting: the download is encrypted as it streams, with
libsodium secretstream and a key for that file, replica and content, which
travels inside the sealed event and is kept on the replica only until the
file is in place.

### Key rotation

Rotation keeps a key both sides trust at every step:

```
drush content-relay:generate-key signing relay_signing_2027 /path/outside/webroot/relay_signing_2027.key
drush content-relay:rotate-key signing relay_signing_2027
# once every peer has confirmed:
drush content-relay:retire-key signing relay_signing_2026
```

`generate-key` writes a new key file (mode 0600) and adds it as an active key.
`rotate-key` first tells peers about it, signed with the current key: the
origin sends each replica a new contract version listing its keys; a replica
sends its origin a subscription update. Then the new key becomes primary and
the old one stays active, so both are accepted and both decrypt. A replica
does not switch until its origin has confirmed. The origin switches at once
but keeps signing for each replica with a key that replica has confirmed, so
an offline replica catches up when it next accepts a contract. `retire-key`
stops using the old key and tells peers to stop accepting it. `content-relay:
keys` lists keys and their status. The same works for encryption keys.

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

To create a key, `drush content-relay:generate-key signing <name> <path>` writes
the value to a file and adds the key entity as active; make the first one
primary on its edit form, or with `content-relay:rotate-key`.

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

Replicas are stored in `content_relay_replica`, and managed under
**Configuration › Web services › Content Relay origin › Replicas**: approve,
suspend, resume or revoke each one, and open its contract.

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

The paired origin is stored in state (`content_relay_replica.origin`).

## Dependencies and external references

Before sending an item, the origin sends everything it references that the
replica subscribes to and does not have yet (content that predates the
pairing, for example), depth first. Items referencing each other are each sent
once; the replica holds the first until the second arrives.

References to anything the replica does not subscribe to (users, when users
are not synced; a bundle outside the contract) are listed in the event as
external. The replica uses the local entity with that UUID if it has one, and
otherwise a fallback set in `content_relay_replica.settings:external_references.fallbacks`:
by default, authors become the anonymous user and other references are left
out. The copy is still verified against the origin's hash, with the same
substitutions applied.

## Files

Files are never published on their own. A file goes to a replica when an
item it receives uses it, in a file or image field or linked in formatted
text, and is sent before that item. Later changes to the file go to the
replicas that have it.

**What is hashed.** A file entity's snapshot carries its location (URI) as
metadata and the SHA-256 and size of its content as hashed data, so moving
a file does not mark it out of sync and replacing its content does. File and
image field items carry their file's checksum, and formatted text lists the
checksums of the files it links, so replacing a linked PDF marks the page out
of sync too. Checksums are cached in `content_relay_file_checksum` while a
file's size and modification time are unchanged.

**Links in text.** On the origin, formatted text fields are scanned in every
translation:

- elements with `data-entity-type` and `data-entity-uuid` (CKEditor uploads,
  `<drupal-media>` and other embeds) make their target a dependency;
- `src` and `href` values pointing at the site's public files, private files
  (`/system/files/…`) or image style derivatives (which resolve to the
  original), relative or absolute on the site's own host or any domain in
  `content_relay.settings:files.own_domains`, become `{cr-file:<uuid>}` or
  `{cr-file:<uuid>:<style>}` tokens, and the file a dependency;
- links to other sites are left alone;
- links that look like the site's files but match no file, or a file missing
  from storage, are left as they are, listed in the event as `unresolved` and
  counted in the report's "Unresolved file links" column.

On a replica, tokens become the replica's own URLs for its copies, or with
`files.links: origin` the origin's URLs (for sites sharing a CDN or object
storage). Hashing turns either back into tokens, so rewritten links never
look like a difference.

**Downloading.** A file entity is created on the replica as soon as its event
arrives. The event carries the file's checksum, size and a signed download
link, valid for `files.link_lifetime` seconds (default an hour) and bound to
that replica. The binary is fetched by the `content_relay_replica_files`
queue, streamed to a temporary file, checked against the checksum and only
then moved into place; a binary already present with the right checksum is
not fetched again. Failures are retried with backoff up to
`files.max_attempts`, then marked failed. An expired link is renewed through
the origin's file endpoint (`GET /content-relay/v1/files/{uuid}`, with the
replica's JWT), which, like the download, serves only files used by content
the replica's contract allows and that is not marked not syncable for it.

Fetching goes through the `content_relay_replica.file_transport` service.
Replace it to get binaries some other way (replicated object storage, a
shared volume); the checksum is still verified.

**Content waiting for files** has the status "waiting for files". With
`files.pending: hold` it is also unpublished until its files arrive, then
republished as received; with `publish` (the default) it is published as
received meanwhile.

**Pulling.** "Pull file" (on a synced file) and "Pull missing files" (on any
synced item) fetch again with a fresh link. Cron checks every synced file
against its checksum every `files.verify_interval` seconds (default daily)
and fetches missing or altered ones. With `files.fetch_on_miss` set to
`redirect` (the default) or `placeholder`, a request for a synced file that
is missing here queues a fetch and redirects to the origin's copy or answers
with a placeholder, instead of a plain 404; for public files this relies on
the web server passing requests for missing files to Drupal, as Drupal's
default `.htaccess` and common nginx configurations do.

**Encryption.** When the contract is encrypted, downloads are too; see
Encryption above.

## Not syncable items and detaching

Either side can stop updates for one item, independently; each side can only
undo its own.

**On the origin**, an item is marked *not syncable* on its edit form, on its
"Not syncable" page (an operation link), with the "Mark not syncable" bulk
action (shipped for content; add one for other types under Actions), or with
`drush content-relay:not-syncable`. The flag (`content_relay_not_syncable`)
applies to every replica or to some. Replicas that already have the item are
sent a `detach` event (they keep their copy) or a `delete`, per
`not_syncable.behavior` or the choice made when marking. A not syncable item
produces no events, is skipped by push and bulk push, and shows as such in
the report. Marking it syncable again sends an `attach` event and the item.

**On a replica**, a synced item is detached from its operation link, in bulk
from the report, or with `drush content-relay:detach`. It keeps its sync state
row, so its UUID stays protected, but events no longer write it: they are
answered 200 `skipped_detached`. Deletes are ignored too, unless
`detach.follow_deletes` is on. The origin is told with a signed notice
(retried by cron if the origin is down; a `skipped_detached` answer corrects
it as well), stops sending creates and updates to that replica, and shows the
item as detached by the replica. Reattaching checks for local edits: without
any, the origin sends its current version; with some, the user accepts the
origin's version (which then replaces the edits) or keeps theirs and stays
detached.

## Manual push

Every item in a published bundle has a "Content Relay" tab (on types with a
page of their own), a "Push now" operation and `drush content-relay:push`. The
user picks replicas and a processing mode, and may force sending a version
the replica already has. Delivery runs in the request and the outcome for
each replica is shown at once: applied, accepted, refused as stale, failed on
the replica (with its reason), or not pushed because the item is not
syncable, detached by the replica, or outside its contract. Pushes are
recorded in the event log with the user who triggered them.

## Status report and bulk push

**Reports › Content Relay status** (`admin/reports/content-relay`) is a View
over `content_relay_report`: one row per item, translation and replica,
including items never sent. Statuses: in sync, out of sync, pending, accepted,
failed, failed on replica, dead-lettered, conflict, not syncable, detached by
replica, never sent. Filters cover entity type, bundle, status, replica,
language, "any translation out of sync", missing translations, "default
translation only" (one row per item), the changed date and label or UUID.
Every column of the table is available to Views, with a relationship to each
entity type's own table, so the view can be changed like any other.

- **Push selected** and **Push all matching filters** confirm the count per
  replica (leaving out not syncable and detached items), can be limited to
  some languages, and record the pushes through the Batch API; delivery then
  happens through the queue as usual. `drush content-relay:bulk-push` takes
  the same filters.
- **Refresh status** recomputes every row in batches. Cron also rebuilds the
  report when replicas, contracts or published bundles change.
- **Download CSV** exports what the filters match, with the view's columns.

The table is kept current by saves, deliveries, flags and notices; it is
derived data and can always be rebuilt.

On a replica, **Reports › Content Relay synced items** lists what was received
with its sync status, and detaches or reattaches selected items in bulk.

## Permissions

| Permission | Side | Allows |
| --- | --- | --- |
| Push Content Relay items | origin | Push now (manual resync) |
| Bulk push Content Relay items | origin | Push from the report, refresh it |
| View the Content Relay status report | origin | The report and its CSV |
| Mark Content Relay items not syncable | origin | Not syncable flags |
| Detach and reattach Content Relay items | replica | Detach, reattach, in bulk too |
| Pull Content Relay items and files | replica | Pull file, pull missing files |
| View the Content Relay replica report | replica | The synced items report |

Every push, flag, detach and reattach is logged with the user who did it.

## Drush

| Command | Side |
| --- | --- |
| `content-relay:push <type> <id or uuid> [--replica=] [--force] [--mode=]` | origin |
| `content-relay:bulk-push [--entity-type=] [--bundle=] [--status=] [--replica=] [--langcode=] [--any-out-of-sync] [--dry-run]` | origin |
| `content-relay:not-syncable <type> <id or uuid> [--replica=] [--behavior=detach\|remove]` | origin |
| `content-relay:syncable <type> <id or uuid> [--replica=]` | origin |
| `content-relay:detach <type> <uuid>` | replica |
| `content-relay:reattach <type> <uuid> [--accept-origin]` | replica |
| `content-relay:pull-file <uuid>` | replica |
| `content-relay:pull-missing-files [<type> <uuid>] [--all]` | replica |
| `content-relay:keys` | both |
| `content-relay:generate-key <purpose> <key id> <path>` | both |
| `content-relay:rotate-key <purpose> <key id>` | both |
| `content-relay:retire-key <purpose> <key id>` | both |

Each exits non-zero when it could not do what was asked.

## Pull authentication

Requests a replica makes to its origin (pulls, schema, files) carry a bearer
token: a JWT the replica issues itself, signed with its Ed25519 key, valid for
two minutes, with issuer, audience, scope and a one-time `jti`. The origin
checks it against the key it registered at pairing. Endpoints depend on
`content_relay.pull_authenticator`, an alias, so another scheme can replace it.

## Pairing

The replica asks, the origin decides.

1. On the origin, choose what to publish under **Content Relay origin ›
   Settings**, then generate a code under **Pairing code**, optionally limited
   to some bundles. Codes are Crockford Base32 (no I, L, O or U), shown in
   groups of four, single-use, valid for 30 minutes by default and stored only
   as a keyed hash. Length (8–64, default 12), lifetime, the flood limit on
   failed attempts (5 per IP per hour) and manual approval are settings.
2. On the replica, create a Content Relay signing key (see Keys), then enter
   the origin's `https://` address, the code and the bundles wanted under
   **Content Relay replica › Pairing**. Input ignores dashes, spaces and case.
3. The replica sends its public keys, its endpoint and the requested terms,
   signed with the key it offers. The origin checks the code, registers the
   replica, proposes contract version 1 and answers with its public keys and
   that contract, signed.
4. The replica stores and acknowledges the contract. Only then, and after an
   administrator's approval where that is required, does the origin send
   events.

Both sites refuse anything that is not HTTPS.

## Subscription contract

The contract says what is sent, how and from when. Each side keeps every
version; each event names the version it was built under, and the replica
judges it by that version, accepting the current and the previous one.
Anything else is answered `409 contract_mismatch`, and the origin sends its
contract again and retries.

- **Replica changes** (bundles, languages, processing mode, rate limit, pause)
  are made under **Content Relay replica › Contract**. The replica sends a
  signed `subscription_update`; the origin answers with the new version,
  which the replica stores and acknowledges. If the origin cannot be reached,
  the change is kept and retried by cron, and the old version stays in force.
- **Origin changes** (which bundles a replica may receive) are made on the
  replica's contract page on the origin. The new version is pushed to the
  replica, which acknowledges it in its response. Unacknowledged versions are
  pushed again by cron. Items of a withdrawn bundle are kept on the replica as
  "detached by origin", never deleted.
- The **effective** terms are what the replica asked for, limited by what the
  origin allows; everything refused is listed with a reason.
- Delivery follows the contract in force: nothing while paused or outside the
  delivery windows, at most the rate limit per minute, and events for bundles
  outside the contract are skipped.

Both sites show the contract in plain language ("You receive Article and
Page in all languages, immediately, unencrypted."), with its version, since
when it applies and a history of every version with what changed.

## Development

From the module directory:

```bash
../../../../vendor/bin/phpcs
../../../../vendor/bin/phpstan analyse . --memory-limit=1G
../../../../vendor/bin/phpunit -c phpunit.xml.dist
```
