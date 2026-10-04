# 20. `ServerConfig` composes three concerns: supplied as one bundle, consumed one slice at a time

## Status

Accepted

## Context

A server's configuration carries four things: the tenant allow-list, the maximum upload size, the allowed stored MIME types, and the server's own base URL. The obvious shape is one `ServerConfig` value object holding all four, with the derived behaviour hung off it: `isTenant()`, `guardUploadSize()`/`guardUploadType()`, `isThisServer()`, and `buildBlobUrl()`.

Held to one object, that config becomes the dependency of six collaborators, and most of them read a single field of it:

- `TenantBlossomPolicy` needs only the tenant allow-list (`isTenant`).
- `BlobDescriptorFactory` needs only the base URL (`buildBlobUrl`).
- `BlossomAuthValidator` needs only the base URL (`isThisServer`).
- `BlobValidator`, `CheckUploadUseCase`, and `CheckMediaUseCase` need only the size and MIME constraints (`guardUploadSize`/`guardUploadType`).

A constructor that asks for the whole `ServerConfig` to read one field lies about what the unit depends on: `TenantBlossomPolicy(ServerConfig)` reads as "needs the server config" when the truth is "needs the tenant set", and nothing stops it reaching a base URL or an upload limit it has no business touching. And **no unit ever needs two of these concerns at once** — tenancy, upload constraints, and server addressing never co-occur at a call site. On the *consumption* side, then, these are three concerns, not one.

But the configuration has a second life the consumption view misses. A host **supplies** all of it at once — parsed from one configuration source, sharing one lifetime — and holds it as a single unit before wiring anything. On that *supply* side "a server's configuration" is one cohesive concept. The design has to serve both readings: whole on the way in, per-slice on the way out. A single object carrying behaviour serves neither well (it over-couples every consumer); three loose objects with no bundle serve the consumption side but force every host to re-assemble the supply side itself.

## Decision

Model the two sides separately.

For **consumption**, split the configuration into three value objects, each owning its data, its invariant, and the behaviour derived from it:

- **`TenantPubkeys`** — the tenant allow-list (already extant; non-empty by construction). `TenantBlossomPolicy` depends on it directly and admits with `contains()`.
- **`UploadConstraints`** — `maxUploadBytes` + `allowedMimeTypes`, owning `guardUploadSize()`, `guardUploadType()`, `isMimeTypeAllowed()`, and the `maxUploadBytes >= 1` invariant. Consumed by `BlobValidator` and the two pre-flight use cases.
- **`ServerIdentity`** — the base URL, owning `isThisServer()` (token scoping) and `buildBlobUrl()` (blob addressing). Consumed by `BlossomAuthValidator` and `BlobDescriptorFactory`.

For **supply**, `ServerConfig` is retained as the bundle: the one value object a host builds from its configuration source and holds as a single field, composing the three concerns and exposing them (`getTenantPubkeys()`, `getUploadConstraints()`, `getIdentity()`). It carries **no behaviour of its own** — it forwards nothing, so it is a cohesive parameter object, not a wrapper over the slices — and it is destructured only at the wiring boundary, where each collaborator is handed the one slice it consumes. Supply is whole; consumption is per-slice.

## Consequences

- Every collaborator's constructor is honest: it asks for the one concern it reads, and cannot reach the other two. A reader learns a unit's real inputs from its signature.
- Each invariant lives beside the data it guards — the positive-`maxUploadBytes` check moves onto `UploadConstraints`, next to `maxUploadBytes`, rather than sitting on a config object that also holds unrelated fields.
- The units are testable in isolation: `BlobDescriptorFactory` is exercised with a `ServerIdentity` alone, with no tenant list or size limit to stand up.
- The host builds one `ServerConfig`, holds it as a unit, and the wiring threads the slices from it — so the single supply-side construction point is kept without making the bundle the dependency of everything downstream. Where that threading reads as a deep chain (`getServerConfig()->getUploadConstraints()->getMaxUploadBytes()`), the fix is a local at the wiring site, never a forwarding accessor on `ServerConfig`.
- There are two opposite temptations, and both are wrong. A future reader must not **re-merge** the three onto one object and hang the behaviour off it to "save a class" — that reintroduces the six over-wide dependencies this split removes. Nor must a reader **delete** `ServerConfig` and push the three onto each host: the concept "a server's configuration" does not vanish when the library stops modelling it — every host re-assembles the same bundle, inflating its own config object or rebuilding the aggregate verbatim, so providing it once here is what keeps it from being duplicated per host.
- The composition getters are pinned by `ServerConfigTest`, and each concern's behaviour by `UploadConstraintsTest`, `ServerIdentityTest`, and `TenantPubkeysTest`.
