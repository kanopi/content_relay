# Content Relay

Keeps content in sync from one Drupal site (the **origin**) to any number of
others (**replicas**) without database dumps. Every change travels as a signed,
optionally encrypted event carrying a full snapshot of the entity, identified
by UUID only, with a canonical hash anyone can recompute to check a copy.

See `docs/decisions.md` for choices that differ from, or refine, the original
specification, and `docs/test-coverage.md` for how each test scenario is
covered.

## Modules

| Module | Role |
| --- | --- |
| `content_relay` | Shared base: event schema, entity serialization, hashing, signing, keys |
| `content_relay_origin` | Publishes changes to replicas: event log, delivery, hash store, pairing, contracts, status report, read endpoints |
| `content_relay_replica` | Receives and applies changes: endpoint, apply, conflicts, mapping, reconciliation, languages, failures |

A site enables one of the two submodules, or both: see "Both roles on one
site" below. **Configuration › Web services › Content Relay**
(`admin/config/services/content-relay`) links to each enabled role's
settings and reports.

## Requirements

- Drupal 10.3 or 11, PHP 8.3 or later.
- The PHP `sodium` extension, strongly recommended. It ships with PHP and is
  enabled in most builds. Without it, `paragonie/sodium_compat` (installed by
  Composer) provides the same functions in plain PHP: correct, but much
  slower, especially for encrypted files; the status report warns about it.
- The [Key](https://www.drupal.org/project/key) module, and File and Views
  (core).
- `lcobucci/jwt` 5.5 or later (installed by Composer).
- A `utf8mb4` database. Hashes cover text byte for byte, and a database that
  cannot store four-byte characters changes the bytes.

## Setup

On both sites, after `composer require drupal/content_relay`:

1. Create keys outside the database, and Key module entries for them:
   `drush content-relay:generate-key signing site_signing ../private/keys/signing.key`
   (and `encryption` likewise, to encrypt). The config and state key
   providers are refused.
2. Enable `content_relay_origin` on the origin, `content_relay_replica` on
   each replica.

On the origin:

3. Choose the bundles to publish at Configuration » Web services » Content
   Relay origin.
4. Generate a pairing code (there, or `drush content-relay:pairing-code`).

On the replica:

5. Pair at Configuration » Web services » Content Relay replica » Pair with
   an origin (or `drush content-relay:pair https://origin.example.com CODE
   --bundles=node:article`) with the origin's HTTPS address and the code.
6. Under Origins, adjust what it receives on the origin's Contract tab, and
   how it applies it on its Settings tab.
7. Make sure cron runs on both sites: delivery, retries, files,
   reconciliation and notices all run from it.

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
suspend, resume or revoke each one, open its contract, and give it settings
of its own. Each group of sending settings (published bundles, delivery, read
limit, not-syncable handling, file links, encryption) follows the site-wide
settings until it is set for the replica; a change to what its contract
offers or requires sends it a new version. A replica that unpairs tells the
origin, which revokes it, so it can pair again with a new code.

## Replica

Events arrive at `POST /content-relay/v1/events` as signed envelopes. The
endpoint verifies the signature and that the sender is one of the paired
origins, works in that origin's pairing from then on, records the sequence
(and any gap), and hands the event to
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

### Several origins

A replica can receive from several origins. **Configuration › Web services ›
Content Relay replica** lists them, each with its contract, its settings, its
reconciliation and Unpair; **Pair with another origin** adds one under a name
of its own (Drush commands about one origin take it as `--origin=`). The
Settings tab beside Origins holds what is not about one origin (the endpoint
URL, how long handled events are remembered) and the settings new pairings
start with. Each origin's record, contract and stream are kept in state and
tables under its pairing; its settings are a config entity,
`content_relay_origin_pairing`, so they deploy like any configuration.

The origin that first sent an item owns it; the others' events for it are
refused as collisions. The synced items report has an Origin column and
filter. A mapping can be for one origin, and then comes before one for every
origin.

Unpairing asks each time what happens to what came from the origin: kept as
this site's own content, no longer synced, or deleted (a batch, as sync
deletes, so this site's own replicas follow). Detached items are this site's
already and are kept either way. The origin is told, and everything kept
about the pairing goes. Kept items are then local content: another origin
takes them over only by adopting them.

### Processing modes

Each event is applied in the request or from a queue:

- **immediate**: applied before answering (200, or the apply answer);
- **queued**: kept in `content_relay_received_event`, answered 202, and
  applied by the `content_relay_replica_inbox` queue worker;
- **auto**: immediate, unless the event brings a file to fetch, its item is
  being written by another process, at least `processing.auto_backlog` events
  are already waiting (default 50), or the load average is at least
  `processing.auto_max_load` (off by default).

The mode comes from the event itself when it carries one (a manual push can
ask for immediate), else from the contract it was built under, per bundle,
else immediate. The replica chooses it on its Contract page.

Applying holds a lock on the item's UUID. A webhook that finds the item
locked waits up to `processing.lock_wait` seconds (default 30), then answers
503 so the origin retries; the queue worker puts the event back.

### Status endpoint

The origin finds out how accepted (202) events ended by sending a signed
`status_request` with up to `processing.status_batch_limit` event IDs (default
200) to the replica's endpoint. The replica answers with a signed `status`
message: for each event `final` (with the answer it would have given at once),
`pending` or `unknown`, plus its contract version and last sequence received.
The origin polls on cron (`content_relay_origin.status_poller`), records final
outcomes as if they had been immediate, sends unknown events again, and sends
the contract again when the versions differ. No callbacks from the replica
are needed.

### Adopting what a copy already has

A replica set up from a copy of the origin's database has the origin's items
under the same UUIDs, but made outside sync, so sync refuses them as
collisions. **Reports › Content Relay synced items › Reconciliation ›
Adopt existing content**, for the origin in question (or `drush
content-relay:adopt`), compares each item
the contract covers with the origin's hashes: identical ones are marked in
sync without a new revision; ones that differ become conflicts, resolved like
any edit made here. It can run at any time, also once sync is under way:
items already synced are skipped, events refused before an item was adopted
are retried after, and differences nobody has resolved are compared again, so
a copy put right since (missing files copied over, say) is adopted then.

### Both roles on one site

A site can be a replica of one site and the origin of others, with both
submodules enabled. Each role keeps its own tables, settings, report and
Drush commands: the origin's report is at `admin/reports/content-relay`, the
replica's at `admin/reports/content-relay/received`, and the commands both
roles have are named `content-relay:origin-…` and `content-relay:replica-…`.
Content received from the origin is passed on to this site's replicas like
any change, but never back to the site it came from, so two sites can also
sync both ways. A new key is announced to the origin and to every replica.

## Read endpoints

Replicas read from the origin with their short-lived JWT; answers are signed
messages, sealed when the contract is encrypted:

- `GET /content-relay/v1/schema`: the synced fields (type, cardinality,
  translatability, required, referenced type, properties) of every bundle the
  replica may subscribe to;
- `GET /content-relay/v1/items/{entity_type}/{uuid}`: one item, as an event
  built under the replica's contract, when the replica may have it;
- `GET /content-relay/v1/files/{uuid}`: a file's metadata and a fresh link;
- `GET /content-relay/v1/hashes?entity_type=&bundle=&after=&limit=`: the
  UUID, latest revision, changed time and hashes of every item of a bundle,
  up to 500 a page in UUID order, read from the stored hashes without loading
  the entities, with the contract version in force;
- `GET /content-relay/v1/stream?sequences=`: what the events with those
  sequence numbers were about, and where each item stands now (exists, gone,
  not_syncable or not_in_contract), for filling gaps.

Each replica has a read budget: `read_limit.items` per `read_limit.window`
seconds (2000 per minute by default; 0 for no limit), counted over items,
hash listings and stream lookups. Past it, a hash page is cut short, and
further reads get `429 rate_limited` with `Retry-After`; the replica keeps
the rest of its work queued until then. File downloads are not counted.

These are custom endpoints rather than JSON:API: they answer in the same
snapshot form as events, with hashes, so a pulled item is applied like any
event; access is decided per replica by its contract and not-syncable flags
rather than by a Drupal user and role; answers are signed and can be
encrypted; and they only read.

## Conflicts and local-only fields

After every apply the replica keeps a hash of its copy. When an update
arrives, the copy is hashed again: if it changed, it was edited here, and
that is a conflict.

- **Pulls** (pull now, reconciliation, re-applying a mapping) never replace
  local edits. The item is marked as in conflict until someone resolves it.
- **Pushed events** follow `conflicts.on_event`:
  - `overwrite` (default): the origin's version is saved as a new revision,
    so the edit stays in the revision before it, and the item is flagged for
    review.
  - `reject`: the local copy stays and the origin is answered `409 conflict`,
    which it shows as a conflict and does not retry.

  - `draft`: with Content Moderation, the origin's version is saved as a
    draft, leaving the edited version live, and the item is flagged.

  Entity types without revisions cannot keep the edit, so they always
  reject.
- Subscribers to `content_relay.conflict` (`ConflictEvent`) are told first,
  and can switch between overwrite, draft and reject.

The "Resolve conflict" page (the item's pull page) shows what differs, field
by field and per language, and offers **Accept origin version** or **Keep
local and detach**. Keeping local after an overwrite restores the edit as a
new revision first. Reattaching an edited item shows the same comparison.

**Local-only fields** are listed per bundle in `local_only` in the replica
settings, and per mapping. Sync never writes them and leaves them out of the
local hash, so editing one is neither a conflict nor out of sync.

## Pull, reconciliation and gaps

**Pull latest from origin** is an operation on every synced item, a form for
pulling any item by UUID (`/admin/content/content-relay/pull`), and
`drush content-relay:pull`.

**Reconciliation** runs from cron (`reconcile.interval`, nightly by default,
0 for never), from each origin's Reconciliation tab, or with
`drush content-relay:replica-reconcile`. For every bundle this site receives
from that origin it reads
the origin's hashes a page at a time and:

- pulls items whose hashes differ, and items missing here;
- deletes items the origin no longer has, after the whole bundle was listed
  (a bundle the origin could not list deletes nothing);
- marks items the origin now keeps from this site as detached by the origin,
  and reattaches ones it sends again;
- reports a contract version different from this site's.

It never touches content without a sync state row, items detached here
(except deletes with "follow deletes"), items in conflict, or items waiting
for files or dependencies. Every change goes through the apply service with
its locks and checks, so a run is safe to repeat and changes nothing when the
sites agree. Each run is recorded with counts and problems on the origin's
Reconciliation tab; a run only ever touches that origin's items. Cron works through a run 30 seconds at a time.

A run acts on at most `reconcile.max_items` items itself (100 by default);
the rest go into a queue that cron works through at the same number per cron
run, as does anything the origin turned away for being over the read limit.
A listing turned away makes the run wait and carry on at a later cron run.
A new run does not start while the queue still has items.

**On the origin**, reconciliation re-sends to each replica what the status
report shows as out of sync, never sent, failed or dead-lettered, as a bulk
push would. It runs from cron (`reconcile.interval`, nightly by default) or
`drush content-relay:origin-reconcile [--replica=]`; at most `reconcile.max_items`
(200 by default) are recorded for sending in the run, the rest are queued
and sent by cron at the same rate.

**Gap filling**: when an event names a predecessor this site never received,
the gap is recorded. On cron (or `drush content-relay:fill-gaps`) the replica
asks the stream endpoint what the missing events were about and settles each
item as reconciliation would.

**Validate** (an operation per item, and `drush content-relay:replica-validate`)
hashes the copy here again and compares it with what it should be, and asks
the origin for its latest hashes to compare with what was received. A copy
changed behind sync's back is marked as an error.

On the origin, `drush content-relay:backfill-hashes` stores hashes for
content saved before the module kept them; the hash listing also computes
missing ones on demand.

## Languages

Translations travel with their item, as one entity with one UUID.

- **What a replica receives** is set in its contract: every language
  (including any the origin adds later) or selected ones, site-wide or per
  bundle. The origin filters before signing, so other languages are never in
  what the replica is sent, not even the item's default language; the
  replica then keeps its own default and flags the item. An item with no
  translation in the replica's languages is not sent, and the origin's
  report shows it as "No languages for this replica". Language-neutral items
  always pass.
- **Language codes** can be mapped (`languages.map` in the replica settings,
  such as `zh-hans` to `zh-cn`). Hashes always use the origin's codes. After
  changing a mapping, `drush content-relay:move-language <from> <to>` moves
  existing translations, a chunk at a time on cron. A received language this
  site does not have is skipped and flagged, or the item rejected
  (`languages.unknown`).
- **Local translations**, made on the replica in a language the origin does
  not send, are never changed or deleted by sync and are not hashed. If the
  origin later sends that language, it is a conflict for that translation:
  accept the origin's, or keep the local one, which detaches that translation.
- **New languages on the origin** are announced to replicas. One receiving
  every language gets them at once; one receiving selected languages shows
  them as pending, to add or ignore (on the origin's Contract tab, or
  `drush content-relay:languages --add=` / `--ignore=`).
- **Removed languages**, by the origin or by changing the subscription, leave
  their translations here as local translations, or delete them if chosen.
  **Added languages** start a backfill run of reconciliation; items waiting
  for it show as "Pending backfill".
- **One translation** can be kept from sync: marked not syncable on the
  origin (`drush content-relay:not-syncable … --langcode=`) or detached on
  the replica (`drush content-relay:detach … --langcode=`). The rest of the
  item keeps syncing.
- **Content Moderation**: the latest revision is sent, drafts included, with
  each translation's state; the replica's moderation decides what is live,
  as on the origin. `moderation.target_state` puts synced content in a state
  of this site's choosing instead. `conflicts.on_event: draft` saves the
  origin's version over local edits as a draft, leaving the edit live.

Changing the languages received needs the "Change the languages received
through Content Relay" permission.

## As it is, or mapped

Each origin bundle is imported **as it is** onto the same bundle here, or
**mapped** onto another structure.

As it is needs the same bundle with the same synced fields (names, types,
cardinality, referenced types). When a bundle is newly asked for on the
Contract page, the origin's schema is checked and the request refused, with
the differences, if it does not fit. `drush content-relay:check-schema`
checks every received bundle.

A **mapping** (Configuration › Content Relay replica › Mappings; a config
entity, so it is exported and deployed) puts one origin bundle onto a bundle
here, possibly of another entity type. It is for every origin, or for one;
one for the origin an event comes from is used first. Each origin field, or one of its
properties (`body.value`), is mapped to a field or property here, with
transforms between:

| Transform | Does |
| --- | --- |
| `static_value` | always `value` |
| `default_value` | `value` when there is none |
| `text_format` | renames text formats (`map`, `default`) |
| `taxonomy_lookup` | the local term with the same UUID (`vocabulary`, `missing: skip|reject`) |
| `date_format` | `from_format` to `to_format`, in `timezone` |
| `concat` | joins with more `sources`, by `delimiter` |
| `callback` | passes each value through a function (`callable`) |

Transforms are plugins (`ContentRelayTransform`); modules can add more. A
whole field mapped without transforms goes through that field's normalizer,
so references, links and file links resolve as they do as it is. Rows with
no origin field give fields here a value from transforms alone, such as a
default. A required field here that is not mapped and has no default refuses
items with a clear error, unless the mapping leaves it to validation. Fields
can be kept local only. Fields not translatable here are filled from the
origin's default language, or one the mapping names.

The form shows type differences, previews the mapping on a real origin item
without saving, and refuses invalid mappings. Saving offers to **re-apply**
the mapping to items already received, which pulls each from the origin and
applies it again in batches (`drush content-relay:reapply-mapping`).

Mapped items keep the origin's hash to compare with the origin, and a local
hash of their own structure for noticing local edits.

**Schema drift.** An event with origin fields that this site's bundle (as it
is) or the mapping (neither mapped nor ignored) does not know is still
applied for the fields it knows, and the item is flagged "schema drift" with
the fields named. Cron also fetches the origin's schema daily, notices
changes, and lists bundles that no longer fit on the status report.

## Dependencies and external references

Before sending an item, the origin sends everything it references that the
replica subscribes to and does not have yet (content that predates the
pairing, for example), depth first. Items referencing each other are each sent
once; the replica holds the first until the second arrives.

References to anything the replica does not subscribe to (users, when users
are not synced; a bundle outside the contract) are listed in the event as
external. The replica uses the local entity with that UUID if it has one, and
otherwise a fallback set in the origin's settings (`external_references.fallbacks`):
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

## Event log, replay and validation (origin)

The event log (Reports » Content Relay event log) lists every event for
every replica, with filters by status, bundle, replica, operation and date.
Each entry shows the replica's last answer. Failed and dead-lettered entries
can be replayed: the item's current version is sent again as a new entry,
and the old one is marked replayed (`drush content-relay:replay <entry>`).

"Validate on replicas", an operation on every published item (and
`drush content-relay:origin-validate <type> <id>`), hashes the item again here,
replacing a stored hash that no longer matches, and asks each replica that
gets it, in a signed `hash_request`, what it last received and whether its
copy is unchanged. Each answer is shown (in sync, behind, changed on the
replica, missing, detached, unreachable) and updates the status report.

## Addresses

Every message is signed, but each side can also accept requests only from
known addresses: per replica on the origin (Replicas » Allowed addresses),
and for the origin on the replica (Settings » Origin addresses). Addresses
and CIDR ranges, IPv4 or IPv6; empty accepts any.

## Save failures (replica)

Valid events that cannot be saved are kept with their payload (sealed to the
replica's own encryption key when the contract is encrypted), and listed at
Reports » Content Relay save failures, with a warning on administration
pages while any are open. Each shows the error, field violations and what
differs from the local copy, with Retry (the stored event, without the
origin), Pull fresh (the origin's current version) and Dismiss (with a
reason; the item stays out of sync). Retry all matching the filters runs as
a batch, after fixing the cause. In Drush: `content-relay:failures`,
`content-relay:retry <ids> | --all [--bundle=]`, `content-relay:dismiss
<id> --reason=`.

## Permissions

| Permission | Side | Allows |
| --- | --- | --- |
| Administer Content Relay origin | origin | Settings: published bundles, delivery, pairing (restricted) |
| Administer Content Relay replicas | origin | Replica list, approval, suspension, contracts, settings per replica, allowed addresses (restricted) |
| Generate Content Relay pairing codes | origin | Pairing codes |
| View the Content Relay event log | origin | The event log and its entries |
| Replay Content Relay events | origin | Replay failed events |
| Push Content Relay items | origin | Push now (manual resync) |
| Bulk push Content Relay items | origin | Push from the report, refresh it |
| View the Content Relay status report | origin | The report and its CSV; validate on replicas |
| Mark Content Relay items not syncable | origin | Not syncable flags |
| Administer Content Relay replica | replica | Pairing, settings, each origin's settings, unpairing (restricted) |
| Change the Content Relay subscription | replica | Propose contract changes (restricted) |
| Detach and reattach Content Relay items | replica | Detach, reattach, in bulk too |
| Pull Content Relay items and files | replica | Pull latest, pull by UUID, pull files, retry and dismiss save failures |
| Resolve Content Relay conflicts | replica | Accept origin version, keep local and detach |
| Change the languages received through Content Relay | replica | Language selection, pending languages |
| Run Content Relay reconciliation | replica | Run reconciliation, see past runs (restricted) |
| Administer Content Relay mappings | replica | Mappings, re-applying them (restricted: the callback transform calls PHP functions) |
| View the Content Relay replica report | replica | The synced items report, save failures, validate |

Every push, flag, replay, detach, reattach, retry, dismissal and language or
address change is logged with the user who did it.

## Drush

| Command | Side |
| --- | --- |
| `content-relay:push <type> <id or uuid> [--replica=] [--force] [--mode=]` | origin |
| `content-relay:bulk-push [--entity-type=] [--bundle=] [--status=] [--replica=] [--langcode=] [--any-out-of-sync] [--dry-run]` | origin |
| `content-relay:not-syncable <type> <id or uuid> [--replica=] [--behavior=detach\|remove] [--langcode=]` | origin |
| `content-relay:syncable <type> <id or uuid> [--replica=] [--langcode=]` | origin |
| `content-relay:detach <type> <uuid> [--langcode=]` | replica |
| `content-relay:reattach <type> <uuid> [--accept-origin] [--langcode=]` | replica |
| `content-relay:pull-file <uuid>` | replica |
| `content-relay:pull-missing-files [<type> <uuid>] [--all]` | replica |
| `content-relay:check-schema` | replica |
| `content-relay:reapply-mapping <mapping>` | replica |
| `content-relay:pull <type> <uuid> [--accept-origin]` | replica |
| `content-relay:replica-reconcile` | replica |
| `content-relay:languages [--add=] [--ignore=]` | replica |
| `content-relay:move-language <from> <to>` | replica |
| `content-relay:fill-gaps` | replica |
| `content-relay:adopt` | replica |
| `content-relay:replica-validate <type> <uuid>` | replica |
| `content-relay:backfill-hashes [--all]` | origin |
| `content-relay:origin-reconcile [--replica=]` | origin |
| `content-relay:replay <entry>` | origin |
| `content-relay:replicas` | origin |
| `content-relay:pairing-code [--bundles=]` | origin |
| `content-relay:origin-status` | origin |
| `content-relay:origin-validate <type> <id or uuid>` | origin |
| `content-relay:pair <origin url> <code> [--bundles=] [--pairing=]` | replica |
| `content-relay:replica-unpair <pairing> --keep\|--delete` | replica |
| `content-relay:replica-status` | replica |
| `content-relay:failures [--status=] [--bundle=]` | replica |
| `content-relay:retry <ids> \| --all [--bundle=]` | replica |
| `content-relay:dismiss <id> --reason=` | replica |
| `content-relay:keys` | both |
| `content-relay:generate-key <purpose> <key id> <path>` | both |
| `content-relay:rotate-key <purpose> <key id>` | both |
| `content-relay:retire-key <purpose> <key id>` | both |

Each exits non-zero when it could not do what was asked. On a replica with
several origins, the commands about one origin need `--origin=<pairing>`.

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
   **Content Relay replica › Pair with an origin**; a site already paired
   also names the new pairing. Input ignores dashes, spaces and case.
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
  are made on the origin's Contract tab under **Content Relay replica**.
  The replica sends a
  signed `subscription_update`; the origin answers with the new version,
  which the replica stores and acknowledges. If the origin cannot be reached,
  the change is kept and retried by cron, and the old version stays in force.
- **Origin changes** (which bundles a replica may receive, whether
  encryption is required) are made on the replica's Contract page or
  Settings page on the origin. The new version is pushed to the
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

## Troubleshooting

- **Nothing arrives on the replica.** On the origin, `drush content-relay:origin-status`
  shows each replica's state and event counts; the event log shows the
  replica's answers. A replica that is pending approval, suspended, or has not
  acknowledged a contract gets nothing. Check that cron runs.
- **"not_receiving", "unknown_sender" or signature refusals.** The sites' keys
  no longer match what each recorded at pairing: rotate keys with
  `content-relay:rotate-key`, which announces the new key, rather than
  replacing key files by hand.
- **"address_not_allowed".** An allowlist is set and the request came from
  elsewhere, for example through a proxy or a changed outbound address.
- **429 "rate_limited" on the replica.** It read more than `read_limit` allows;
  its queued work resumes on later cron runs.
- **Items stuck in "waiting for files".** `drush content-relay:pull-missing-files
  --all`, and check that the origin's file endpoint is reachable.
- **Items in "error" (hash mismatch).** Something on the replica changes
  content on save (a hook, a field default). Validate the item; local-only
  fields can take such fields out of the comparison.
- **Save failures.** Reports » Content Relay save failures shows why; fix the
  field, language or mapping, then retry.
- **Gaps in the stream.** `drush content-relay:replica-status` on the replica lists
  them; cron fills them, or `drush content-relay:fill-gaps`.
- **Out of sync after an outage.** Run reconciliation (`drush
  content-relay:replica-reconcile` on the replica, and `drush
  content-relay:origin-reconcile` on the origin to re-send what failed).
- **Slow signing or file encryption.** The status report says whether the
  sodium extension is missing.

## Development

From the module directory:

```bash
../../../../vendor/bin/phpcs
../../../../vendor/bin/phpstan analyse . --memory-limit=1G
../../../../vendor/bin/phpunit -c phpunit.xml.dist
```
