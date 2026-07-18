# 0013. The package's collections reuse the shared `TypedCollection`, not a bespoke set

## Status

Accepted

## Context

This package carries three collection-shaped concepts: the list of descriptors a `list` returns,
the server's tenant allow-list, and the server's allowed-MIME-type list. `nostr-core` already
ships a `TypedCollection` base — a validating constructor that guarantees element type at the
boundary, `count`/`getIterator`/`toArray`/`isEmpty`, and a lazily-memoised membership index that
answers `contains()` in O(1) — plus a ready-made `PublicKeyCollection` leaf with `contains()` and
`toHexes()`.

Each concept could instead hand-roll its own array, membership map, and iterator. Doing so
duplicates, per concept, the exact mechanism the shared base owns, and re-implements a key-indexed
`contains()` that `PublicKeyCollection` already provides for pubkeys. Two things then read like a
smell to a later reader:

1. The allowed-type list is a plain typed collection that permits duplicates and answers membership
   from an index, rather than a bespoke "set" value object that deduplicates on construction. An
   allow-list "obviously should be a set", so a reviewer is tempted to rebuild the deduping map.
2. `TenantPubkeys` wraps a `PublicKeyCollection` instead of being one (or instead of callers using
   `PublicKeyCollection` directly), which looks like redundant indirection.

## Decision

The collections reuse the shared machinery rather than re-implementing it:

- `BlobDescriptorCollection` and `AllowedMimeTypes` are `final` `TypedCollection` leaves, filed by
  their structural kind under `Domain/Collection/`. Each supplies only its element type (and, for
  the allow-list, a one-line `contains()` that wires its value to the base's membership index).
  They are **lists**: ordered, duplicate-permitting, membership via the index — the same semantics
  every collection in the ecosystem has. An empty `AllowedMimeTypes` is a valid deny-all on stored
  bytes (see ADR-0005).
- `TenantPubkeys` is a value object that **composes** a `PublicKeyCollection`. It exists for the one
  invariant a bare collection cannot carry — a tenant set must be non-empty, because a server with
  no tenants can authorise nothing (ADR-0005) — and canonicalises its input to a unique set at
  construction. Membership, `toArray`, and `toHexes` delegate to the composed collection; none of
  that behaviour is re-implemented here.

## Consequences

- There is one way to test membership (the base's index), one validating constructor, one iterator
  contract. No concept re-declares an index field or an `IteratorAggregate`/`Countable` body.
- The split is principled: a `TypedCollection` leaf is a list; a value object that needs an
  invariant a list cannot express (`TenantPubkeys`' non-emptiness) composes a collection and adds
  only that invariant. This is why the tenant set canonicalises to unique while the allowed-type
  list does not — the former is a curated value object, the latter a plain collection.
- A future reader must not "restore" `AllowedMimeTypes` to a hand-rolled deduping set, nor inline
  `TenantPubkeys`' membership map back onto itself: both reintroduce a second copy of the mechanism
  the shared base exists to own. The collection tests pin the element-type guard and the membership
  behaviour.
