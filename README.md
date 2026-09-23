# Nostr Blossom Package

[![CI](https://github.com/johninnis/nostr-blossom/actions/workflows/ci.yml/badge.svg)](https://github.com/johninnis/nostr-blossom/actions/workflows/ci.yml)

A PHP library implementing the [Blossom](https://github.com/hzrd149/blossom) (Blob Storage on Nostr) protocol — the BUD specifications — built with Clean Architecture principles, on top of [`innis/nostr-core`](https://github.com/johninnis/nostr-core).

## Overview

Blossom is a small, sharply-scoped protocol: content-addressed blobs (SHA-256), kind-24242 authorisation events, and a handful of HTTP endpoints. This package provides the **protocol layer only** — the spec primitives and use cases — so a server, a client, or a CLI can build on Blossom without inheriting any particular storage backend, HTTP runtime, or framework.

- **Spec, not server.** No filesystem, database, HTTP, or framework dependency — the only external library is `innis/nostr-core` for event/signature primitives.
- **Domain and Application layers.** The Domain holds the value objects, enums, and the typed `BlossomFailure` hierarchy; the Application holds the use cases, the host ports, and kind-24242 auth validation. A host implements the ports.
- **Immutable value objects, side effects at the edges.** Descriptors, hashes, and queries are immutable; the use cases are thin orchestration that pushes every side effect (store, index, discard) out through injected ports.
- **Composed by a host.** `innis/hubstr-blossom` is one host that wires it to amphp + SQLite + the filesystem; another can be written against any backend by implementing the ports.

## Requirements

- PHP 8.4 or higher
- [`innis/nostr-core`](https://github.com/johninnis/nostr-core) `^0.6` (event, signature, tag, and collection primitives)

## Installation

```bash
composer require innis/nostr-blossom
```

## Quick Start

This is the protocol layer: a host implements the ports (storage, index, inspector, clock, …) and wires them into the BUD use cases. Every use case returns either its result value or a typed `BlossomFailure`, so a host maps a failure to its HTTP status without parsing messages — `$error->category()->httpStatus()`.

For a complete, runnable walkthrough — in-memory ports wired into an authorised upload and a public read — see [`examples/blob_lifecycle.php`](examples/blob_lifecycle.php) (`php examples/blob_lifecycle.php`).

### Handle an upload (BUD-02)

```php
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\UploadBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;

// $store, $index, $inspector, $clock implement the host ports; $config and
// $signatureService are built as in the Authorisation example below.
$validator = new BlossomAuthValidator($signatureService, $clock, $config->getIdentity());
$policy = new TenantBlossomPolicy($config->getTenantPubkeys());
$ingestor = new BlobIngestor(
    new BlobValidator($inspector, $config->getUploadConstraints(), $validator),
    new BlobDescriptorFactory($config->getIdentity(), $clock),
    $store,
    $index,
);
$upload = new UploadBlobUseCase($validator, $policy, $ingestor);

$result = $upload->execute($authHeader, $pendingBlobSource, $declaredHash);

if ($result instanceof BlossomFailure) {
    http_response_code($result->category()->httpStatus()); // 401/403/413/415/…
    return;
}

echo json_encode($result); // BlobDescriptor: url, sha256, size, type, uploaded, nip94
```

### Serve a blob (BUD-01, public by hash)

```php
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\GetBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

$hash = BlobHash::tryFromHex($sha256); // ?BlobHash — null unless 64 hex chars
if (null === $hash) {
    http_response_code(404);
    return;
}

// TenantBlossomPolicy defaults to GetAccessPolicy::Public, so reads are public by
// hash; pass a different GetAccessPolicy to gate them. $validator and $config are
// built as in the Authorisation example below.
$validator = new BlossomAuthValidator($signatureService, $clock, $config->getIdentity());
$policy = new TenantBlossomPolicy($config->getTenantPubkeys());
$blob = new GetBlobUseCase($store, $index, $validator, $policy)->execute($hash, $authHeader);
if ($blob instanceof BlossomFailure) {
    http_response_code($blob->category()->httpStatus()); // 404
    return;
}

// $blob is a RetrievedBlob: $blob->getDescriptor() (metadata) + $blob->getPath() (bytes to stream)
```

## Architecture

This package follows Clean Architecture with strict layer separation:

- **Domain Layer** — immutable value objects (`BlobHash`, `BlobDescriptor`, `DeclaredBlob`, `IncomingBlob`, `MediaMetadata`, `ListQuery`, `HttpUrl`, `MimeType`, the three configuration concerns `TenantPubkeys` / `UploadConstraints` / `ServerIdentity`, and the `ServerConfig` composition root that holds them (see [ADR-0020](docs/adr/0020-server-config-composes-three-concerns.md))), the typed `Collection/` leaves (`BlobDescriptorCollection`, `AllowedMimeTypes`) built on `nostr-core`'s `TypedCollection`, the `BlossomVerb`, `GetAccessPolicy`, and `BlossomFailureCategory` enums, and the `BlossomFailure` hierarchy. The `MimeType` value object owns MIME normalisation, the generic-type sentinel, and the type-to-extension table, so nothing else re-derives them. No external dependencies beyond `innis/nostr-core` primitives.
- **Application Layer** — the BUD use cases (classes that orchestrate the host ports and return either their result value or a `BlossomFailure`), the ports a host implements (`BlobStoreInterface`, `BlobIndexInterface`, `BlobReportStoreInterface`, `BlobInspectorInterface`, `PendingBlobSourceInterface`, `RemoteBlobFetcherInterface`, `MediaOptimiserInterface`, and `ClockInterface` from `nostr-core`), the single `BlossomPolicyInterface` authorisation contract (with the ready-made `TenantBlossomPolicy`), kind-24242 authentication (`BlossomAuthValidator`), the write-pipeline services (`BlobValidator` decides storability, `BlobDescriptorFactory` assembles the descriptor, and the `BlobIngestor` coordinator owns the temp file; plus `BlobRemover` for deletes), and the `PendingBlob`/`StorableBlob`/`RetrievedBlob` DTOs that carry a staged-but-unstored upload, a validated blob ready to persist, and a retrieved blob's descriptor-plus-path respectively.

A host provides Infrastructure adapters for the ports plus a Presentation layer (HTTP routing, blob delivery); none of that lives here.

Value objects expose state through `getX()`/`toX()` accessors, never public properties (see [ADR-0001](docs/adr/0001-value-objects-keep-getter-methods-not-property-hooks.md)).

### Design rationale

The decisions that read like smells at the call site are recorded as immutable, numbered ADRs under [`docs/adr/`](docs/adr/). Read those before "simplifying" anything here — start with [ADR-0000](docs/adr/0000-record-architecture-decisions.md).

## Host responsibilities

Because this package is the protocol layer only, a few security- and trust-sensitive concerns are deliberately the host's to enforce in its port adapters. They are not omissions — they cannot be decided without the runtime (network, filesystem, framework) the package refuses to depend on.

- **Mirror fetches are an SSRF surface — the `RemoteBlobFetcherInterface` adapter must defend it.** `MirrorBlobUseCase` validates the caller's authorisation and then hands the *client-supplied* `HttpUrl` to the host's fetcher. The package does not (and cannot) know which hosts are safe to reach, so the adapter is responsible for blocking private, loopback, and link-local address ranges, refusing unexpected redirects, and capping the fetched size and time. Treat the URL as hostile input even though it arrived inside an authorised request. To keep that adapter from re-parsing what the package already parsed, `HttpUrl` exposes `getScheme()`, `getHost()`, and `getPort()`: a fetcher resolving a relative or scheme-relative redirect `Location` rebuilds the absolute target from those accessors rather than running the string back through `parse_url`.
- **The `BlobInspectorInterface` adapter is trusted to report honest bytes.** The size, content hash, and detected MIME type it returns are taken at face value by the ingestor (the hash *is* re-derivable and the package compares it to any client-declared hash, but the size and detection are the inspector's word). An inspector that streams must enforce the host's own hard read limit so a truthful-but-huge upload cannot exhaust memory before `UploadConstraints`' `maxUploadBytes` guard is reached.
- **Per-tenant storage isolation lives in the `BlobIndexInterface` adapter.** `list` and `deleteForTenant` are passed the authenticated `PublicKey`; the adapter must scope its query and delete to that tenant. The use cases enforce that a caller may only list and delete *its own* blobs, but the physical isolation is the index implementation's to honour.
- **A host's allowed-type config parses through the strict constructor.** `MimeType` has two named constructors chosen by trust boundary: `MimeType::fromString` never fails — it degrades an unrecognised or malformed HTTP `Content-Type` to `application/octet-stream` (the BUD/NIP-94 fallback) — while `MimeType::tryFromString` returns `null` on anything that is not a bare, well-formed `type/subtype`. A host building its `AllowedMimeTypes` from configuration strings must use `tryFromString` and fail loudly on `null`, so a typo'd entry (`imagepng`) is a startup error rather than a silent `application/octet-stream` allowance. The reasoning is in [ADR-0005](docs/adr/0005-validation-strategy-by-trust-boundary.md) and [ADR-0017](docs/adr/0017-mimetype-strict-parser-for-configuration.md).
- **Transport conformance is the host's — CORS pre-flight and the error reason header.** The package has no HTTP layer, so a host must emit the BUD-mandated CORS pre-flight itself: `OPTIONS` on every Blossom endpoint answers with `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Headers: Authorization, *`, and `Access-Control-Allow-Methods` naming the verbs that path serves (optionally `Access-Control-Max-Age: 86400`). On an error, the host maps the returned `BlossomFailure` to its response: `$error->category()->httpStatus()` for the status and `$error->getMessage()` for the human-readable `X-Reason` header the Blossom clients read. The failure hierarchy is designed to carry that message — the host is responsible for surfacing it.

## Supported BUDs

| BUD | Description | Support |
|-----|-------------|---------|
| [BUD-01](https://github.com/hzrd149/blossom/blob/master/buds/01.md) | Server requirements & `GET`/`HAS` | One `GetBlobUseCase` resolves a blob to its descriptor and stored path; a host serves `GET` with the body and `HEAD` without it. Reads are public by hash by default, or gated to authenticated signers / tenants by a `GetAccessPolicy`, or to tenant-owned blobs by the `TenantOwnedBlossomPolicy` decorator |
| [BUD-02](https://github.com/hzrd149/blossom/blob/master/buds/02.md) | Upload | Content-addressed upload; reads stay public by hash. (Upstream has since moved the delete and list endpoints into BUD-12 — see below) |
| [BUD-04](https://github.com/hzrd149/blossom/blob/master/buds/04.md) | Mirror | Mirror-from-`HttpUrl` use case via the `RemoteBlobFetcherInterface` |
| [BUD-05](https://github.com/hzrd149/blossom/blob/master/buds/05.md) | Media optimisation | Optimise-on-upload use case via the `MediaOptimiserInterface`, plus a `CheckMediaUseCase` for the `HEAD /media` pre-flight: it runs the optimiser-support, size, and `x`-tag guards on the client's declared `X-Content-Length`/`X-Content-Type`/`X-SHA-256` (`DeclaredBlob`), judging the declared type by `MediaOptimiserInterface::supports()` rather than the storage allow-list — the same admission rule the `PUT /media` applies |
| [BUD-06](https://github.com/hzrd149/blossom/blob/master/buds/06.md) | Upload requirements | Max size and allowed types exposed on `UploadConstraints` for a host to advertise, plus a `CheckUploadUseCase` for the `HEAD /upload` pre-flight: it runs the size, MIME, and `x`-tag guards on the client's declared `X-Content-Length`/`X-Content-Type`/`X-SHA-256` (`DeclaredBlob`) through the same admission path the `PUT` uses, so the pre-flight and the `PUT` judge the declared values identically (the `PUT` can still reject when the *detected* content disagrees with the declaration) |
| [BUD-08](https://github.com/hzrd149/blossom/blob/master/buds/08.md) | Nostr File Metadata Tags | `nip94` tags on the descriptor, derived from the `MediaMetadata` a host's inspector supplies |
| [BUD-09](https://github.com/hzrd149/blossom/blob/master/buds/09.md) | Blob report | Kind-1984 report validation and persistence via `ReportBlobUseCase`, which takes a raw event body (no kind-24242 `Authorization` header), checks the event names a blob, and verifies the event's own signature directly — it does not use `BlossomAuthValidator` or a `BlossomPolicyInterface` ([ADR-0010](docs/adr/0010-report-use-case-verifies-signature-directly.md)) |
| [BUD-11](https://github.com/hzrd149/blossom/blob/master/buds/11.md) | Endpoint authorization | Kind-24242 `Authorization` event parsing and validation in `BlossomAuthValidator`: kind, verb (a token naming more than one is refused — [ADR-0024](docs/adr/0024-a-token-naming-two-verbs-is-refused-not-resolved.md)), `created_at` reasonableness ([ADR-0016](docs/adr/0016-created-at-checked-for-reasonableness-not-strict-past.md)), expiration ([ADR-0023](docs/adr/0023-any-stated-expiry-that-has-passed-expires-the-token.md)), and `server`-tag scoping ([ADR-0018](docs/adr/0018-server-tag-scoping-enforced-at-parse.md)); required `x`-tag binding on the write verbs ([ADR-0006](docs/adr/0006-upload-x-tag-required.md)) and `x`-tag scoping on gated reads ([ADR-0015](docs/adr/0015-gated-get-honours-x-tag-scoping.md)) |
| [BUD-12](https://github.com/hzrd149/blossom/blob/master/buds/12.md) | Delete & list | Per-tenant-isolated delete (via `BlobRemover`, which removes the stored bytes only when no tenant still indexes the hash — see [ADR-0011](docs/adr/0011-reference-counted-delete.md)) and paginated list (since/until) |

## Authorisation

Authentication and authorisation are two separate seams, sequenced by the use case so the cheap check always gates the expensive one.

**Authentication — `BlossomAuthValidator`.** Blossom authorisation events are Nostr kind `24242` events carrying a `t` (verb), an `expiration`, and optionally `x` (blob hash) and `server` (domain) tags. The validator is pure authentication and knows nothing about tenants — it takes a `ServerIdentity` only to know its own domain for the `server`-tag scoping check. It splits into two calls:

- `parse(BlossomVerb, $authHeader): Event|BlossomFailure` runs the cheap, trust-free envelope checks — header format, base64/JSON, kind, verb (a token naming more than one distinct verb is refused rather than resolved by tag order; see [ADR-0024](docs/adr/0024-a-token-naming-two-verbs-is-refused-not-resolved.md)), `server`-tag scoping (a token naming other servers only is rejected; see [ADR-0018](docs/adr/0018-server-tag-scoping-enforced-at-parse.md)), `created_at` reasonableness (see [ADR-0016](docs/adr/0016-created-at-checked-for-reasonableness-not-strict-past.md)), and expiration (a token must state one, and any stated expiry that has passed expires it, whatever the tag order; see [ADR-0023](docs/adr/0023-any-stated-expiry-that-has-passed-expires-the-token.md)) — and returns the parsed (but not yet signature-verified) `Event`.
- `verify(Event): ?BlossomFailure` runs the one expensive check, secp256k1 signature verification.

**Authorisation — one `BlossomPolicyInterface`.** A single contract answers "may this actor perform this action on this blob" for every verb: `allowUpload`, `allowMedia`, `allowList`, `allowDelete`, and `allowGet(?PublicKey, BlobHash)` (see [ADR-0009](docs/adr/0009-one-policy-interface-for-all-verbs.md) for why one interface rather than a port per verb). The ready-made `TenantBlossomPolicy` admits only the configured tenants (a non-empty `TenantPubkeys` allow-list) for the write verbs, and its read behaviour is chosen by a `GetAccessPolicy` enum passed at construction — `Public` (reads are public by hash, the default and the BUD-01 norm), `Authenticated` (any valid signer), or `Tenant` (tenants only). Restricting a tenant to only the blobs it owns is the fourth rule, and because it is the one that needs a `BlobIndexInterface`, it is a separate decorator rather than an enum case: `TenantOwnedBlossomPolicy` wraps a `Tenant`-mode policy and adds the per-blob ownership check, so the index is a required constructor argument that cannot be omitted —

```php
$readPolicy = new TenantOwnedBlossomPolicy(
    new TenantBlossomPolicy($config, GetAccessPolicy::Tenant),
    $index,
);
```

The split between the enum and the decorator is recorded in [ADR-0008](docs/adr/0008-get-access-policy-modes-on-one-policy.md). A host that wants a different rule implements the one interface; there is no second authorisation abstraction to learn.

**Ordering — every cheap check gates the expensive one.** Every authenticated use case runs `parse()` → policy admission → any other request-bound checks (`x`-tag binding or scoping, declared-value guards) → `verify()` → act, so a request that fails any of those cheap checks never costs the server a secp256k1 operation. (The ingest paths' binding against the staged bytes' hash necessarily follows verification — that hash exists only once the bytes do.) Both validator calls return a union, so the type system makes a caller narrow before touching the event. The security trade-off admission-first accepts (a tenant-probe oracle on already-public pubkeys) is recorded in [ADR-0003](docs/adr/0003-admit-before-verify.md), and the general request-bound-checks-first rule in [ADR-0019](docs/adr/0019-request-bound-checks-precede-signature-verification.md). The boundary is what a check *reads*: a check that reads private server state runs only **after** `verify()` — which is why `GetBlobUseCase` verifies the signature before its `allowGet` policy check, whose `TenantOwnedBlossomPolicy` form reads private per-blob ownership — see [ADR-0014](docs/adr/0014-read-verifies-before-ownership-check.md). Public reads still short-circuit before any parse or verification.

**Binding a request to a specific blob** is a separate step: `requireAuthorisedBlob()` returns a `BlossomFailure` unless the validated event carries a matching `x` tag (and `null` when it does), so a caller chains it after `verify()` like any other check. Every write path enforces this binding: `BlobIngestor` rejects an upload, mirror, or optimise whose auth event has no `x` tag matching the hash being stored (`AuthorisationFailure::blobNotAuthorised`), and `DeleteBlobUseCase` requires the same `x` tag for the hash being deleted. When [ADR-0006](docs/adr/0006-upload-x-tag-required.md) was recorded, BUD-02 phrased the upload `x` tag as a *SHOULD* and this package deliberately required it; the spec has since been restructured and BUD-11's endpoint table now marks the `x` tag **required** for upload, mirror, media, and delete — the spec caught up with the decision. Either way, an authorised pubkey can never store or delete a blob its signed event did not name. On a **gated read** the `x` tag stays optional — a tag-less get token is valid for any blob the policy admits — but when the token does carry `x` tags they scope it: `requireBlobInScope()` rejects a get whose token names other blobs only, so a leaked scoped token cannot be replayed across the server (see [ADR-0015](docs/adr/0015-gated-get-honours-x-tag-scoping.md)).

`BlossomVerb` covers all five authenticated actions — `upload`, `media`, `delete`, `list`, and `get`. The `get` verb is only consulted when a host has configured a non-public `GetAccessPolicy`. Under the default public reads, `GetBlobUseCase` serves the blob without ever consulting the validator — an `Authorization` header, valid or not, is ignored rather than allowed to turn a public read into a `401`; a header is parsed and verified only when the policy actually gates the read.

```php
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Collection\AllowedMimeTypes;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerIdentity;
use Innis\Nostr\Blossom\Domain\ValueObject\TenantPubkeys;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

$signatureService = Secp256k1Signer::create();
$tenant = PublicKey::tryFromHex($tenantPubkeyHex) ?? throw new InvalidArgumentException('invalid tenant pubkey');
$baseUrl = HttpUrl::tryFromString('https://blossom.example.com') ?? throw new InvalidArgumentException('invalid base url');
$config = new ServerConfig(
    new TenantPubkeys([$tenant]),
    new UploadConstraints(
        104857600,
        new AllowedMimeTypes(array_map(
            static fn (string $type): MimeType => MimeType::tryFromString($type) ?? throw new InvalidArgumentException('invalid allowed mime type'),
            ['image/png', 'image/jpeg'],
        )),
    ),
    new ServerIdentity($baseUrl),
);

// Each collaborator is wired from the slice it needs (see ADR-0020).
$validator = new BlossomAuthValidator($signatureService, $clock, $config->getIdentity());
$policy = new TenantBlossomPolicy($config->getTenantPubkeys());

// A use case sequences these for you; this is the shape it follows.
$event = $validator->parse(BlossomVerb::Upload, $authHeader);
if ($event instanceof BlossomFailure) {
    // $event->category()->httpStatus() gives the BUD-appropriate status code
}

$denied = $policy->allowUpload($event->getPubkey())  // cheap admission first
    ?? $validator->verify($event);                   // then the secp256k1 check
if (null !== $denied) {
    // $denied->category()->httpStatus()
}

// $event is now authenticated and authorised
$blob = BlobHash::tryFromHex($sha256) ?? throw new InvalidArgumentException('invalid hash');
if (null !== $validator->requireAuthorisedBlob($event, $blob)) {
    // the auth event does not cover this blob
}
```

## Error handling

The use cases split failures into two channels, so a host can map them to the right HTTP status without inspecting messages:

- **Anticipated failures are returned, never thrown.** Every outcome the use case can foresee — missing or invalid authorisation, a disallowed MIME type, an over-size/unreadable/hash-mismatched blob, an unknown hash, a failed mirror fetch, a crashed optimiser — comes back as a typed union (`BlobDescriptor|BlossomFailure`, `RetrievedBlob|BlossomFailure`, or `?BlossomFailure` for write-only paths). A host narrows with `instanceof BlossomFailure`, then reads `$error->category()->httpStatus()` for the BUD-appropriate code (401, 403, 404, 413, 415, but also 502 for an upstream mirror failure and 500 for an optimiser fault). A returned `BlossomFailure` is therefore not always a `4xx`.
- **Unanticipated infrastructure faults propagate as exceptions.** Once a request is validated, the writes performed by a host's own ports (`BlobStoreInterface::store`, `BlobIndexInterface::save`, `BlobReportStoreInterface::save`) are expected to succeed; if such a port throws, that exception bubbles for the host to map to `5xx`.

The reasoning behind these two channels — failures as returned values rather than exceptions, the absence of a `BlossomException` base, returning upstream/internal failures, the category-to-status mapping, and the four trust-boundary validation strategies — is recorded in [ADR-0002](docs/adr/0002-failures-are-returned-values-not-exceptions.md), [ADR-0005](docs/adr/0005-validation-strategy-by-trust-boundary.md), and [ADR-0007](docs/adr/0007-http-status-on-domain-category.md).

## Media metadata (BUD-08)

The three write paths — upload, mirror, and optimise — all funnel through one write pipeline: a `BlobValidator` verifies the blob (readable, within size, authorised, and — for whatever finally gets stored — an allowed type judged by detected content rather than the declared header) and yields a `StorableBlob`, which the `BlobIngestor` coordinator persists. The allow-list gates the *stored* bytes: on the optimise path the input is admitted by `MediaOptimiserInterface::supports()` and only the optimiser's output is checked against the allowed types, so a server can accept a format it will not store and normalise it into one it will. A host's `BlobInspectorInterface` returns an `IncomingBlob`; when that inspector also decodes the image it can attach a `MediaMetadata` (pixel `dimensions` and a `blurhash`). When present, the `BlobDescriptorFactory` folds those into the descriptor's NIP-94 `nip94` tags, so the uploading client can lift ready-made `dim`/`blurhash` straight into its kind-1063 or `imeta` without re-processing the file. On the optimise path the descriptor also carries the pre-optimisation hash as `ox`. Hosts that store bytes without decoding them — and where no optimisation happened — leave `MediaMetadata` off, and the descriptor omits `nip94`. The write pipeline's shape (the `BlobValidator` / `BlobDescriptorFactory` / `BlobIngestor` split, the optimiser passed per-call, and the coordinator's ownership of the temp file) is recorded in [ADR-0004](docs/adr/0004-blob-ingestor-shape.md).

## Testing

```bash
# Full suite + PHPStan level 9 (ship gate)
composer test

# Unit suite only
composer test-unit

# Full suite with coverage (HTML + text + clover)
composer test-coverage

# PHPStan analysis (level 9)
composer analyse

# Fix / check code style
composer fix-style
composer check-style

# Apply / check automated refactorings
composer rector
composer check-rector
```

## License

MIT License. See LICENSE file for details.
