# Security Policy

## Reporting a vulnerability

Please report suspected vulnerabilities privately, not as a public issue:

- Open a private advisory via GitHub Security Advisories on this repository, or
- Email <john@innis.xyz>.

Please include a description, the affected version, and a reproduction if you have one. You can expect an acknowledgement within a few days. Because this is a pre-1.0 library, fixes ship in the next `0.x` release; there is no long-term-support branch.

## What this package is — and why that shapes the threat model

`nostr-blossom` is the **protocol layer only**. It has no I/O of its own: no network, no filesystem, no database, no process, no clock. Every side effect is performed by a host through a port this package merely *defines* (`BlobStoreInterface`, `BlobIndexInterface`, `BlobInspectorInterface`, `RemoteBlobFetcherInterface`, `MediaOptimiserInterface`, `BlossomPolicyInterface`, and `ClockInterface` from `nostr-core`).

The consequence is blunt and worth stating plainly: **the security-critical boundaries of a Blossom server are in the host's port adapters, not in this library.** This package can enforce the parts of the protocol that are pure logic (authorisation binding, content-address verification, reference-counted deletes, typed failures); it *cannot* defend the network, filesystem, or decoder, because it never touches them. The sections below draw the line precisely so a host does not assume a protection that is actually its own to provide.

## Host responsibilities (these are load-bearing — do not skip them)

These are not optional hardening. Each is a real attack surface that this library structurally cannot close, because closing it requires the runtime the library refuses to depend on.

### 1. Mirror fetches are an SSRF surface — the `RemoteBlobFetcherInterface` adapter must defend it

`MirrorBlobUseCase` authenticates the caller and then hands the **client-supplied** `HttpUrl` to the host's fetcher. The package cannot know which hosts are safe to reach, so the adapter is responsible for:

- blocking private, loopback, link-local, and cloud-metadata address ranges (resolve the host and check the *resolved* IP, not just the literal, to defeat DNS rebinding);
- refusing or tightly bounding redirects, and re-validating the redirect target the same way;
- capping fetched size and wall-clock time.

Treat the URL as hostile input even though it arrived inside an authorised request. `HttpUrl` exposes `getScheme()`, `getHost()`, and `getPort()` so the adapter can rebuild a relative or scheme-relative redirect target without running the string back through `parse_url`. As of the current release `HttpUrl::tryFromString` also rejects any input containing whitespace or control characters, but that is input hygiene, **not** an SSRF defence — the defence is the adapter's.

### 2. The `BlobInspectorInterface` adapter is trusted to report honest bytes

The size and detected MIME type the inspector returns are taken at face value. (The content hash is the exception: it is re-derived and compared against any client-declared hash, so a lying hash is caught.) An inspector that streams **must enforce the host's own hard read limit** so that a truthful-but-enormous upload cannot exhaust memory *before* `ServerConfig`'s `maxUploadBytes` guard is ever reached. The size guard runs on the inspector's reported size; it cannot bound a read the inspector performed before returning.

### 3. Per-tenant storage isolation lives in the `BlobIndexInterface` adapter

`list`, `deleteForTenant`, and `ownsBlob` are handed the authenticated `PublicKey`. The use cases enforce that a caller may only list, delete, and (in gated-read modes) access *its own* blobs — but the **physical** scoping of those queries to that tenant is the adapter's to honour. A store that ignores the pubkey and returns cross-tenant rows defeats the isolation the use cases assume.

### 4. A media optimiser decodes hostile bytes

`MediaOptimiserInterface` runs on client-supplied content. Image and video decoders have a long CVE history; run the optimiser with the same suspicion as any other untrusted-input parser (resource limits, and ideally process isolation).

### 5. Build your allowed-type configuration with the strict parser

`MimeType::fromString` never fails: an unrecognised or malformed `Content-Type` degrades to `application/octet-stream` (the BUD/NIP-94 mandated fallback). That leniency is correct for an untrusted upload header but a footgun on the *configuration* path — a typo'd allow-list entry (`imagepng`) would silently become the generic type. A host loading its allow-list from configuration must therefore parse each entry with `MimeType::tryFromString` and fail loudly on `null`, so the typo is a startup error instead of an unintended `application/octet-stream` allowance. See [ADR-0005](docs/adr/0005-validation-strategy-by-trust-boundary.md) and [ADR-0017](docs/adr/0017-mimetype-strict-parser-for-configuration.md).

### 6. Transport conformance — CORS pre-flight and the error reason header — is the host's

The package has no HTTP layer, so the browser-facing CORS contract is structurally the host's to emit. Blossom's browser clients depend on it: the host must answer `OPTIONS` on every endpoint with `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Headers: Authorization, *`, and `Access-Control-Allow-Methods` naming that path's verbs (optionally `Access-Control-Max-Age: 86400`). A missing or wrong pre-flight is not merely non-conformant — it is what lets a browser reach the authenticated endpoints at all, so getting `Access-Control-Allow-Headers` wrong silently breaks `Authorization`-bearing requests. On an error the host maps the returned `BlossomFailure` to the response: `category()->httpStatus()` for the status (see [ADR-0007](docs/adr/0007-http-status-on-domain-category.md)) and `getMessage()` for the human-readable `X-Reason` header. The failure hierarchy carries that message precisely so the host does not have to invent one; a host that drops it leaves clients with a bare status and no cause.

## Accepted trade-offs (deliberate, and why they are acceptable)

### Admit-before-verify leaks tenancy of an already-public pubkey

Every authenticated **write** runs the cheap policy admission before the expensive secp256k1 verification, so an unadmitted caller is rejected without the server paying a curve operation. The same rule extends to every other check computable from the request itself and public configuration — the `x`-tag binding and the pre-flights' declared-value guards also precede verification (see [ADR-0019](docs/adr/0019-request-bound-checks-precede-signature-verification.md)), so a request that fails any cheap check never costs a curve operation. The cost is that an unauthenticated caller can learn whether a given pubkey is a configured tenant, because an admitted-but-unsigned request fails later than an unadmitted one. This is deliberate: tenant pubkeys are already public (they sign visible events and advertise this server in their kind-10063 lists), so there is no secret to protect, and paying secp256k1 on every unadmitted request would hand an attacker a CPU-exhaustion vector to guard a non-secret. See [ADR-0003](docs/adr/0003-admit-before-verify.md).

### Gated-read modes are a niche; the default and common case is public-by-hash

Blossom is a protocol for sharing files **publicly**: the overwhelmingly common deployment serves blobs public-by-hash, and under that configuration a read never consults the auth validator or a policy at all — there is no authentication surface on the read path to attack. The optional gated read modes (`Authenticated`, `Tenant`, and the `TenantOwned` decorator) exist for the rare private instance. On that path a private per-blob ownership read must not be exposed before the caller's identity is proven, so `GetBlobUseCase` verifies the signature **before** the authorising policy check on gated reads — closing an ownership-probe oracle that would otherwise let an unauthenticated caller learn whether a tenant holds a given blob. See [ADR-0014](docs/adr/0014-read-verifies-before-ownership-check.md). This ordering applies only to gated reads; public reads still short-circuit before any parse or verification.

## What the library does guarantee

- **Content addressing is verified.** The stored bytes' hash is re-derived and compared to any client-declared hash (`BlobIntegrityFailure` on mismatch).
- **Authorisation is bound to specific content.** Every write path requires the signed kind-24242 event to name the blob via a matching `x` tag; an authorised pubkey cannot store or delete bytes its event did not name. See [ADR-0006](docs/adr/0006-upload-x-tag-required.md). On gated reads the binding is the client's choice, but it is honoured: a get token carrying `x` tags is valid only for the blobs it names, so leaking a scoped token does not leak the rest of the server. See [ADR-0015](docs/adr/0015-gated-get-honours-x-tag-scoping.md). The same holds across servers: a token carrying `server` tags is honoured only when one of them names this server's domain, so a token minted for another server is rejected here. See [ADR-0018](docs/adr/0018-server-tag-scoping-enforced-at-parse.md).
- **Deletes are reference-counted.** On content-addressed, shared storage, deleting one tenant's copy never erases bytes another tenant still indexes. See [ADR-0011](docs/adr/0011-reference-counted-delete.md).
- **Anticipated failures are typed return values, never thrown**, so a host cannot silently forget to handle one. See [ADR-0002](docs/adr/0002-failures-are-returned-values-not-exceptions.md).
