# Decision record

Choices that differ from, or make concrete, the build specification. Each says
what was decided, why, and what it affects.

## 1. Replay protection is per message, not per event

The specification says the receiver rejects "already-processed event IDs".
Taken literally, an origin that times out waiting for a response and retries
the same event would be refused as a replay, and the event dead-lettered,
even though the replica may never have received it.

Instead, every delivery attempt is a new signed message with its own ID, and
the verifier refuses a message ID it has seen. The event ID inside stays the
same across retries and replays; the replica's apply service (phase 2)
recognizes an event it has already applied and answers with its earlier result
instead of applying it twice. The security property (a captured message cannot
be replayed) is unchanged, and retries work.

## 2. Empty fields are left out of snapshots

A field with no values does not appear in a snapshot, and a snapshot without a
field means the field is empty. Otherwise adding a field on the origin would
change the hash of every existing entity of that bundle, and the report would
mark them all out of sync although no content changed.

## 3. "Changed" timestamps are synced but not hashed

Fields of type `changed` go into the snapshot's metadata section. They are
applied on the replica so it shows the origin's dates, but a re-save that
changes nothing else must not produce a new hash. Staleness checks use the
event's `source_changed` and revision, not the hash. Normalizer plugins can
put other informative values in metadata the same way (file paths, phase 6).

## 4. Values are cast to their declared type

The same integer can be `"1"` after a database load and `1` or `TRUE` in
memory. The generic normalizer casts every primitive property to its typed
data type before hashing, and leaves NULL properties out (casting would turn
them into `0` or `""`). Without this the origin's hash at save time and the
replica's hash after loading would differ for identical content.

## 5. References that cannot be matched by UUID

- **Configuration entities** (text formats, roles, bundles) are referenced by
  machine name, which is already the same on every site.
- **The anonymous user** is user 0 everywhere but has a different UUID on each
  site, so it travels as the marker `anonymous`.
- **Other users** are matched by UUID like any entity. If users are not synced,
  references to them cannot be resolved on the replica. *Open question:* fall
  back to a configurable replica user, or require user sync for authored
  content?

## 6. Keys live in the Key module, as key types

Content Relay keeps no list of its keys. It provides two Key module key types,
one per purpose, and every key entity of those types is one of the site's key
pairs. The primary/active/retired status is that key's key type setting, edited
on the Key module's own form. Rotation is therefore done entirely in the Key UI:
add a key, make it primary, make the old one active, later retired.

The specification requires that private keys never appear in exported config
or the database. The Key module's `config` provider stores the value in the key
entity's exported configuration and `state` stores it in the database, so both
are refused, on the key form and when the key is read. File and environment
providers work. The key types offer value generation, but the Key module only
generates for providers that accept a value, which are exactly the refused
ones; with a file or environment provider the value is generated outside the
form and placed where the provider reads it.

## 7. Key IDs are derived from the public key

A key ID is the first 16 hex characters of SHA-256 over the purpose and public
key. Both sites compute the same ID from the public key alone, so there is no
assigned identifier to keep in step during pairing or rotation.

## 8. The default translation is always in a snapshot

A snapshot cannot exist without its default translation, so it is included
even when the receiver's language filter excludes it. Whether to send an item
whose only wanted language is not its default is decided by the sender before
building the snapshot (phase 11).

## 9. Signatures are domain-separated

Signed bytes are prefixed with `content_relay.message.v1` so a signature over a
message can never be presented as a signature over anything else made with the
same key, such as the JWTs in phase 3.

## 10. Drupal 10.3 support

Supported because it costs little: hooks are thin procedural wrappers that
delegate to services, and plugins use PHP attributes, both of which 10.3 has.
Tests carry annotation and attribute forms so PHPUnit 9 and 11 both read them.

## 11. Snapshots are built when an event is sent, not when it is recorded

The origin's event log row records that an entity changed, not the change.
The snapshot is built at delivery from the entity as it is then. This keeps
saves cheap (no serialization per replica, no payload storage), makes every
retry and replay send the current state as the specification requires for
replays, and lets several updates queued for one entity go out as one event;
the others are marked `superseded`.

The hash is still computed at save time for the hash store, as specified.

## 12. Gaps are detected through `previous_sequence`, not consecutive numbers

Because superseded rows are never sent, a replica may legitimately see
sequence 5 followed by 8. Each event therefore carries `previous_sequence`,
the sequence of the last event actually sent to that replica. A gap is an
event whose predecessor the replica never received. This field was added to
the event schema before any release, so it is still version 1.

## 13. Delivery to a replica is strictly ordered

The oldest pending event for a replica blocks the ones behind it while it
waits for a retry. A replica that is down receives nothing until it recovers,
then everything in order. One undeliverable event delays a replica by at most
its retry schedule before it is dead-lettered and the stream moves on.

## 14. Responses the specification leaves open

- **Refused messages** (bad signature, unknown key or sender, replay, wrong
  recipient, stale timestamp) are answered 403; malformed ones 400. Both are
  permanent for the origin, which dead-letters them.
- **A UUID that matches replica-owned content** is a permanent apply failure
  (422 `apply_failed`, code `uuid_collision`). It is recorded in the
  replica's failed events so the replica's administrators see it, rather than
  only logged.
- **Transient failures and missing dependencies** are answered 202: the
  replica has the event and will apply it itself. The origin learns the
  outcome through the status endpoint (phase 8).
- **Responses are not signed.** They travel over HTTPS in answer to a signed
  request and change only the origin's bookkeeping. Signing them can be added
  if the status endpoint needs it.

## 15. Peers are stored outside configuration

The origin's replica registry is a table and the replica's origin is in
state. Both hold public keys and addresses for one environment's pairing; a
config import from another environment must not change whom a site trusts.

## 16. Exception summaries name the root cause class only

Entity storage wraps every exception in `EntityStorageException`. The summary
sent to the origin names the innermost exception's class (for example
`RuntimeException`) and nothing else; its message, which can contain paths or
values, stays in the replica's failed events table.

## 17. JWT pull authentication

Pairing and every contract message are signed messages, which already prove
who sent them. JWTs are for the plain read requests a replica makes to its
origin. `lcobucci/jwt` is a dependency of the module and of the project, and
`JwtAuthenticator` is in place behind `PullAuthenticatorInterface`; the
endpoints that use it arrive with pulls, schema and files.

## 18. A pairing request is signed by the key it registers

Before pairing the origin knows no key for the replica. The request is
therefore signed with the signing key it carries, whose ID must be the one
derived from it. This proves the replica holds the private key it registers;
the code proves it was invited. The origin's keys reach the replica in the
pairing response, authenticated by HTTPS, and verify everything after.

## 19. When a contract version takes effect

The specification sets `effective_from_sequence` when the version is
proposed, and also says the origin keeps using the previous version until the
new one is acknowledged. Both hold: the signed terms keep the sequence known
at proposal, and on acknowledgement the origin records the first event not yet
sent as the sequence the version actually applies from (shown in the history).
The replica does not need it: each event names its contract version.

An event for a bundle that is not in the contract in force when it is sent is
skipped, even if a pending version adds the bundle. Holding it would stall the
whole stream on an acknowledgement that may be lost. Content saved in that
window reaches the replica through the backfill and reconciliation that adding
a bundle triggers (phase 10).

## 20. Pairing codes fold look-alike letters

Codes are Crockford Base32, which has no I, L, O or U. Input is folded the
Crockford way (I and L to 1, O to 0) as well as ignoring dashes, spaces and
case, so a code read aloud or copied from a screen still works. Codes are
stored as an HMAC keyed with the site's hash salt, so a database dump alone
cannot be used to recover them.

## 21. Switching the replica to selected languages

The specification asks that switching from "all languages" to "selected"
pre-selects the languages currently received. Until the origin's language list
is available from its schema endpoint (phase 9), the form pre-selects the
replica's own enabled languages when none are ticked.

## 22. Entity coverage is by field kind, not by entity type

The specification lists entity types to support. Instead, everything that can
hide a site-specific value is handled by the kind of field it is in (reference,
embedded entity, link, system path, moderation state) wherever that field
appears, and only what is truly particular to one entity type has an entity
plugin. A test enables a wide set of modules and every `entity_test` type,
asks which types and bundles can be synced, and round-trips a sample entity of
each, so a type that does not work shows up as a failure rather than a gap.

## 23. Embedding requires a composite target

An Entity Reference Revisions field is embedded only when its target type is
composite (declares the parent keys, as paragraphs do). Otherwise it is an
ordinary reference, so pointing such a field at nodes does not copy whole
nodes into their referrers.

## 24. References outside the subscription have a local fallback

This answers the open question on authors. An event lists the entities it
references that the replica will never receive. The replica uses its own
entity with that UUID if there is one, and otherwise a fallback per entity
type: by default the anonymous user for authors, and nothing for other
references. Subscribed references the replica lacks still make the item wait.
Sites that want real authors subscribe to users.

## 25. Pending drafts are not synced yet

Snapshots are built from the default revision, the one that is live. A draft
saved on top of published content (a forward revision) is not sent until it
is published, and moderation state is synced for the translations of the
default revision. Sending forward revisions, so a replica can show the same
drafts, belongs with the multilingual and moderation work in phase 11.

## 26. Things a replica must not generate itself

Modules that create content from other content on save will collide with
synced content. Pathauto is the common case: synced path aliases arrive as
entities, and a replica that also generates aliases for synced nodes gets two.
Disable generation for synced bundles on replicas.

## 27. The status report reads a table of its own

The report needs one row per item, translation and replica, including items
never sent, across every entity type, with filters and sorts on every column.
A query joining the event log, hash store, replica answers and flags at view
time cannot be expressed as one View over all entity types, and would be
slow. So `content_relay_report` holds the derived rows: recomputed for an
item whenever something about it changes (save, delivery outcome, flag,
notice), and rebuilt in chunks when replicas, contracts or published bundles
change (cron compares a fingerprint) or on "Refresh status". Views reads it
like any table, with a relationship to each entity type's own table.

## 28. One row per translation, with a filter for one row per item

The specification asks for an expandable per-language breakdown or a "one
row per translation" option. The shipped view has one row per translation
and an exposed "Default translation only" filter that turns it into one row
per item. An expandable display would need custom JavaScript and does not
survive a site builder changing the view; the filter does.

## 29. Detaching is per side, and deletes still reach detached items

The origin's flag and the replica's detach are stored separately on both
sides, so each can only undo its own and an item detached by both is sent
again only when both have reversed. Deletes of an item the replica detached
are still sent: whether to follow them is the replica's setting, and the
origin cannot know it. The origin learns about a replica detach from the
replica's signed notice and from any `skipped_detached` answer; a notice that
cannot be delivered is kept and resent by cron, and an applied update clears
a stale record.

## 30. "Remove" is a delete event with a reason

Marking an item not syncable with the remove behavior records an ordinary
delete with reason `not_syncable`, the only kind of delete sent while the
origin still has the item. Every other delete is dropped at send time if the
entity exists again.

## 31. A manual push is never folded into an earlier event

Queued updates of an item normally go out as one event. A push is exempt: it
is sent itself, after anything queued before it, so the processing mode and
force the user chose apply. When the replica already has that revision it
answers "unchanged" without a new revision.

## 32. Reattaching over local edits forces the next version to apply

A replica normally answers "unchanged" to the revision it already has. After
the user accepts the origin's version over local edits (or the origin
reattaches an item edited while detached), the item's status is set to
`error` so the next event is applied even if the origin's content is the same
revision. The reattach page lists which translations differ; a field-level
diff needs the origin's version, which arrives with pulling in phase 10.

## 33. "Refresh status" recomputes locally for now

The specification has it use the replica's batch hash endpoint, which is part
of phase 10. Until then it recomputes from what the origin knows: what each
replica acknowledged. Phase 10 adds asking the replicas.

## 34. Views is a dependency of both submodules

Both reports are Views, shipped as configuration, so `views` is a dependency
rather than an optional extra.

## 35. The push result names the event log entry by number

The event log pages arrive with the remaining admin UI in phase 12. Until
then the push form shows the entry's number rather than a link.

## 36. Files follow the content that uses them

Files are not a bundle to publish or subscribe to. A replica receives exactly
the files its content uses, sent before that content, and later changes to a
file go only to replicas that have it. The file endpoint and download links
apply the same rule, through a record of which items use which files kept
with each item's hash.

## 37. A file's location is metadata, its content is hashed

The specification says a file path change alone must not change the hash and
a content change must. So a file entity's URI travels as metadata and its
SHA-256 and size are hashed, and every reference to a file (field items, and
the list of files linked in a text) carries the file's checksum. A replica
that already has a file at that URI that it did not sync, or another file
entity there, gets a free name next to it rather than overwriting it.

## 38. File links in text are tokens; query strings are dropped

A link to one of the site's files becomes `{cr-file:<uuid>}`, or
`{cr-file:<uuid>:<style>}` for an image style derivative. The derivative's
`itok` and any other query string or fragment on a file URL are not kept:
each site generates its own derivative URLs. Links to files that do not exist
are left untouched and reported, not dropped.

## 39. Download links are signed with the origin's key, and root-relative

No new secret: the origin signs the file UUID, replica ID and expiry with its
Ed25519 signing key and checks them against its own public keys. Links are
relative to the origin URL the replica paired with, so they do not depend on
what URL the origin believes it has during a queue run. The download and the
file endpoint answer 404 both for files that do not exist and for files the
replica may not have, so neither can be used to probe.

## 40. Applying ignores "no access to the referenced entity"

Reference fields check that the current user may see each referenced entity.
Events are applied by whoever handles the request, typically anonymous for a
webhook, so that check says nothing about the content; whether the target
exists is still checked.

## 41. File encryption arrives with message encryption

Encrypting a file only helps if its key travels inside an encrypted event,
and encrypted events are phase 7. The streaming cipher was built and tested
in phase 6 and is switched on with the encryption mode in the contract; see
decision 45.

## 42. Links left at the origin use derivative URLs by convention

With `files.links: origin`, a link to an image style derivative is rebuilt as
the origin's standard derivative path, without `itok`. That works where the
derivative already exists on the origin (served by the web server) or where
the origin allows derivatives without a token; otherwise prefer local links.

## 43. Sign, then seal, with sealed boxes

A sealed message is the signed message and its signature, encrypted together
with `sodium_crypto_box_seal` to the recipient's X25519 key. Sealed boxes are
anonymous; the signature inside is what authenticates the sender. The
envelope shows only the version, sender, signing key ID, encryption key ID,
mode and ciphertext, as the specification asks.

## 44. The contract decides the mode; a peer policy applies it

Every message to a peer is sent in the mode of the contract in force with it
(before the first acknowledgement, the pending one), so the existing callers
did not change: the signer asks a per-peer policy, which each role module
provides. A stronger mode than expected is always accepted; a weaker one only
under the rules in the README (contract unencrypted, or within the
transition window). The window starts when the origin activates the contract
and when the replica stores it.

## 45. File keys are derived, not random and stored

The specification asks for a random key per file. Downloads can happen hours
after the event is built, so a random key would have to be stored on the
origin until then. Instead the key is a keyed hash, under the origin's
private encryption key, of the file's UUID, the replica and the content
checksum: unique per file, replica and version, unpredictable without the
private key, and recomputed when the file is downloaded. The signed link
names which encryption key to derive from, so it can be checked and survives
a rotation while the old key is active.

## 46. The file endpoint answers with a signed message

Its answer can carry a file key, so it is a signed message from the origin,
sealed when the contract is encrypted, like every other message, rather than
plain JSON over the JWT-authenticated request.

## 47. Key rotation announces before it switches

A new key is announced, signed with the current key, before it is used: in a
new contract version from the origin, in a subscription update from a
replica. A replica waits for its origin's confirmation; the origin does not
wait for every replica but signs for each with a key that replica has
confirmed in its acknowledged contract. Retiring a key removes it from what
the peer is told, and the peer stops accepting it.

## 48. "Require encryption" is a site setting

Each side's policy is `content_relay.settings:encryption.require`: not a
secret, and per site. It is set on the origin's settings form and on the
replica's Contract page, and each contract records both sides' settings.
Changing it on the origin issues new contract versions to every replica.

