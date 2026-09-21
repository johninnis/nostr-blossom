# 0005. Value-object validation strategy is chosen by trust boundary

## Status

Accepted

## Context

The value objects appear to validate inconsistently: `BlobHash::tryFromHex` returns `null` on bad input, `MimeType::fromString` never fails, `ListQuery` rejects an out-of-range limit, and `UploadConstraints`/`TenantPubkeys`/`IncomingBlob` throw `InvalidArgumentException`. The empty-set rules also differ: `TenantPubkeys` rejects an empty allow-list, while `AllowedMimeTypes` permits one. A reader could mistake this for four unrelated styles and an oversight.

## Decision

There is one rule — **match the failure mode to where the input comes from** — applied at four different trust boundaries:

- **Untrusted parse-or-reject input returns `null`** (`BlobHash::tryFromHex`, `HttpUrl::tryFromString`). A value parser of untrusted input must not throw.
- **An HTTP `Content-Type` with a spec-mandated fallback degrades** to `application/octet-stream` (`MimeType::fromString`). BUD/NIP-94 mandate this fallback for an unrecognised upload header.
- **A query specification rejects an out-of-range or out-of-order bound rather than clamping** (`ListQuery`). A list query holds no result set, so "clamp to my maximum" has nothing to truncate — the only honest behaviours are to accept the bound as stated or to reject it. `ListQuery` takes the two-path shape the ecosystem's query value objects use: a **strict constructor** for trusted construction that throws `InvalidArgumentException` on a limit outside `[1, MAX]` or a `since` after `until`, and a **`tryFromQueryString` parser** for the untrusted HTTP query string that runs the *same* validity checks and returns `null` on any malformed or out-of-range field — never throwing. An absent limit takes the default page size. The host hands the raw query string to the parser and maps a `null` to its own response (a 400, or its own clamp); the value object never silently rewrites the caller's request.
- **Programmer-supplied construction throws** `InvalidArgumentException` (`UploadConstraints` rejects a non-positive `maxUploadBytes`; `TenantPubkeys`/`AllowedMimeTypes` reject non-`PublicKey`/ non-`MimeType` members; `IncomingBlob`/`BlobDescriptor` reject a negative `size`). These are invariant guards on trusted internal values, matching how `nostr-core`'s `Timestamp` and `EventKind` validate in their constructors.

The empty-collection asymmetry is deliberate: `TenantPubkeys` rejects empty because a server with no tenants can authorise nothing — it cannot function. `AllowedMimeTypes` permits empty because that is the canonical way to express a server that serves, deletes, and accepts reports but stores no new bytes (an empty allow-list is a deny-all on upload/mirror/optimiser output, not a default-allow).

## Consequences

- `MimeType::fromString`'s leniency is correct for an untrusted upload header but a footgun on the *configuration* path: a typo'd allow-list entry (`imagepng`) silently becomes the generic type. A host loading its allow-list from config must validate those strings itself, rejecting anything that round-trips to `application/octet-stream` it did not intend, before constructing `AllowedMimeTypes`. (See the README "Host responsibilities".)
- The constructor throws do not break ADR-0002's *no-anticipated-path-throws* invariant: none of these values come from protocol input. A negative size is a bug in a host's inspector, in the same category as a host's store throwing; and `BlobValidator` already converts any `Throwable` from `inspect()`/`inspectWithMedia()` into a returned `BlobReadFailure`, so even a misbehaving inspector surfaces to the caller as a typed value.
- `ListQuery` rejecting an out-of-range limit (rather than clamping it) keeps a list query honest: a value object that holds no result set never silently mutates the caller's stated request. A future reader must not "restore" clamping for convenience — the host, which faces the client and can hold a result set, is where any leniency belongs.
- A future reader must not "unify" these into one strategy, nor make `AllowedMimeTypes` reject empty: each boundary's rule is pinned by its value-object test.
