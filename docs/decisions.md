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

## 17. JWT pull authentication waits for the endpoints that need it

Pairing and every contract message are signed messages, which already prove
who sent them; none of phase 3 needs a bearer token. `lcobucci/jwt` is added
with the first replica-to-origin read endpoint (pull, schema, file), so the
dependency arrives with code that uses it.

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
