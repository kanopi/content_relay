# Multiple pairings, each with its own configuration

Status: proposal, not built.

Today a replica has one origin, and both roles keep their settings site-wide.
This changes that: a replica can pair with several origins, an origin already
pairs with several replicas, and every pairing, on either side, has its own
settings, which can be edited, and can be ended.

## Decisions taken

- **Unpairing** asks what to do with what was received: keep it as ordinary
  local content (sync state dropped; adopting can link it again after
  re-pairing), or delete everything received from that origin.
- **Overlap**: the first origin to create an item on a replica owns it. Another
  origin's events for the same UUID are refused as collisions, as for content
  made locally, and listed as failures.
- **Replica settings per pairing**: everything about receiving.
- **Origin settings per pairing**: per replica, with the site-wide settings as
  defaults.

## Model

Each pairing is two things, kept apart on purpose:

- **Its settings**, a config entity, so they are exported, deployed and can
  live in a config split (the China site's pairing in the `china` split):
  - replica side, `content_relay_origin_pairing` (one per origin);
  - origin side, `content_relay_replica_pairing` (one per replica).
- **Its live state**, in the database, keyed by the pairing's machine name:
  the peer's site ID, address and public keys, contract history, stream
  position, received events, failures, reconciliation runs and sync state.
  Keys rotate and sequences move; that must not change configuration.

Pairing creates both. Unpairing deletes both, after the content question.
A pairing whose settings arrive by config import but has no live state shows
as "not paired yet", with a Pair button that uses those settings.

### Replica: what moves into the pairing

Everything in `content_relay_replica.settings` except site-wide plumbing:
conflicts, moderation target state, languages (map, unknown, removed),
local-only fields, external reference fallbacks, deletes when detached,
files (pending, links, fetch on miss, retries, timeout), processing
(auto backlog and load), reconciliation interval and size, transient retry
limit, allowed addresses. Mappings (`content_relay_mapping`) gain a pairing.
Site-wide stays: the webhook URL, retention, lock waits.

The webhook endpoint stays one URL; the sender's site ID selects the pairing.

### Origin: what moves into the pairing

Published bundles (defaulting to the site-wide list), not-syncable behavior,
require encryption and transition window, delivery (batch size, attempts,
backoff, timeouts), read limits, file link lifetime, reconciliation interval
and size, allowed addresses. The site-wide settings remain as defaults for new
pairings and for pairings that do not override them.

### Ownership

Sync state records the pairing that created each item. An event from another
pairing for that UUID is refused with `uuid_collision`, naming the owner.
Unpairing with "keep" releases ownership, so another origin, or adopting, can
take the items over.

## UI and Drush

- Replica: **Content Relay › Origins** lists pairings (label, address, status,
  contract, last event) with Edit (label, address, receiving settings,
  subscription changes as today), Unpair, and Pair with another origin.
- Origin: **Replicas** gains Edit for the per-replica settings beside the
  existing suspend and revoke.
- Reports gain a pairing filter and column; item pages name the pairing.
- Drush commands that act on one pairing take `--origin=` (replica) or keep
  `--replica=` (origin), required when there is more than one;
  `content-relay:unpair <pairing> --keep|--delete` is new.

## Migration

Not needed for released sites (there are none), but the existing single
pairing becomes the pairing `default` on update, so the Smalley test sites
keep working.

## Phases

1. Replica pairing entity and live-state tables keyed by pairing; settings
   readers switched to the pairing; one pairing still enforced.
2. Several origins on a replica: endpoint routing by sender, ownership,
   per-pairing reconciliation, files and failures; reports and Drush.
3. Origin per-replica settings entity, with site-wide defaults.
4. Edit and unpair on both sides, including the keep or delete choice.
5. Docs, decision records and the two Smalley sites moved to the new model.

Each phase ends with PHPCS, PHPStan, the full suite and a commit.
