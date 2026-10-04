# 28. The package's collections reuse nostr-core's typed and keyed collections, not a bespoke set

## Status

Accepted

Supersedes ADR-0013. Its decision, that the collections reuse the shared machinery and that `TenantPubkeys` composes a `PublicKeyCollection`, is carried forward unchanged. What changed is where the shared membership index lives: innis/nostr-core 0.9.0 moved it from `TypedCollection` to a `KeyedCollection` subclass, so ADR-0013's description of `AllowedMimeTypes` (a `TypedCollection` leaf with a one-line `contains()` wired to the base's index) no longer names code that exists.

## Context

This package carries three collection-shaped concepts: the list of descriptors a `list` returns, the server's tenant allow-list, and the server's allowed-MIME-type list.

innis/nostr-core 0.9.0 ships two bases. `TypedCollection` is the validating, ordered list: element type guaranteed at construction, `count`/`getIterator`/`toArray`/`isEmpty`. `KeyedCollection` extends it for elements that implement `IdentityKeyedInterface`: a final `contains()` answered from a lazily memoised index of each element's own `identityKey()`, plus `unique()`, `intersect()` and `diff()` (nostr-core ADR-0078). `PublicKeyCollection` is a ready-made keyed leaf.

The allowed-type list is the one collection here that is asked membership, so it needs the keyed base; the descriptor list is only built, iterated and serialised, so it needs no more than the typed base.

## Decision

- `AllowedMimeTypes` is a `final` `KeyedCollection<MimeType>` leaf. `MimeType` implements `IdentityKeyedInterface`, its identity key being its normalised essence, so membership is the base's `contains()` and the leaf supplies only its element type. It is a list: ordered and duplicate-permitting. An empty `AllowedMimeTypes` is a valid deny-all on stored bytes (ADR-0005).
- `BlobDescriptorCollection` is a `final` `TypedCollection<BlobDescriptor>` leaf, adding only its JSON serialisation.
- `TenantPubkeys` is a value object that composes a `PublicKeyCollection`. It exists for the one invariant a bare collection cannot carry, a non-empty tenant set (ADR-0005), and canonicalises its input with the collection's own `unique()`. Membership, `toArray` and `toHexes` delegate to the composed collection.

## Consequences

- There is one way to test membership, the keyed base's index over each element's identity key. No leaf declares a `contains()`, an index field, or an iterator body.
- An element type that is asked membership must implement `IdentityKeyedInterface`; the type system, not a review, stops a leaf from wiring a second key.
- A future reader must not restore `AllowedMimeTypes` to a hand-rolled deduping set, give it its own `contains()`, nor inline `TenantPubkeys`' membership back onto itself: each reintroduces a second copy of the mechanism the shared bases own. The collection tests pin the element-type guard and the membership behaviour.
