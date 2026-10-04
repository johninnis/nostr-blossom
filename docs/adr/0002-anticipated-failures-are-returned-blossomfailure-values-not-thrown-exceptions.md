# 2. Anticipated failures are returned `BlossomFailure` values, not thrown exceptions

## Status

Accepted

## Context

Every Blossom endpoint has foreseeable failure modes: missing or invalid authorisation, a disallowed MIME type, an over-size or unreadable blob, an unknown hash, a failed mirror fetch, a crashed optimiser. PHP has no checked exceptions, so a thrown error is invisible to the static analyser and a caller can silently forget to handle it. A returned value that is part of the type forces the caller to narrow before using the result, and the analyser fails the build if it does not.

Three things about the result read like mistakes, and so are recorded here:

1. `BlossomFailure` is a class *hierarchy* — an abstract base with leaf classes — and a hierarchy usually signals behaviour that should have been shared by forwarding to a collaborator, not by extending a base.
2. There is no `BlossomException` base class, even though throwing-style code would have one.
3. If `BlossomFailure` is never thrown, it is not obvious where a fault *would* root if this package ever threw one.

## Decision

Anticipated outcomes are **returned**, never thrown. Each use case and `BlossomAuthValidator` returns a typed union — `BlobDescriptor|BlossomFailure`, `RetrievedBlob|BlossomFailure`, or `?BlossomFailure` for a write-only path. On success the return *is* the value, typed; there is no wrapper to unwrap and no `mixed` to assert.

`BlossomFailure` is a sealed family of value objects: an abstract `readonly` base carrying the message and an abstract `category()`, with a `final readonly` leaf per failure category. This is a discriminated union, not inheritance-for-reuse. The base is the union's root — a closed set of failure categories named as distinct types a caller can match on — and it owns no mechanism that the leaves share by forwarding. Modelling several distinguishable outcomes as one matchable, sealed type is precisely what a discriminated union is for, and is a different thing from extending a base to inherit an implementation.

There is **no `BlossomException` base class.** This package's anticipated failures are values, not throwables; `BlossomFailure` extends nothing in the exception hierarchy. A base exception here would be either dead code or a standing invitation to start throwing the very outcomes the typed-return design keeps in the signature.

Were this package ever to throw a package fault, that fault would root at `NostrException` (defined in `nostr-core`), because a fault is rooted by *whose code raises it*, not by the dependency graph: nostr-blossom is Nostr-domain library code. Today it throws no such fault.

## Consequences

- A host maps a failure to an HTTP status with `$failure->category()->httpStatus()` (see ADR-0007) — no message parsing, no `try`/`catch` around the protocol layer.
- The invariant that keeps this honest: **no anticipated path throws.** Every foreseeable, client-controlled input — a malformed `expiration` tag, a bad mirror URL, a crashed optimiser — becomes a returned `BlossomFailure`. Upstream and internal failures (`502`/`500`) are returned too: the boundary is *anticipated vs unanticipated*, not *client vs server*.
- The only things that throw are a host's own port faults (its store/index/report write failing — the host's to map to `5xx`) and invalid value-object construction (see ADR-0005).
- A future reader must not collapse the `BlossomFailure` family into its base, nor introduce a `BlossomException`. The sealed family is pinned by tests asserting each category's `httpStatus()` and by `BlossomFailureCategoryTest`.
