# 29. An access gate sequences every authorisation, and a blob archive pairs the store with the index

## Status

Accepted

## Context

Two concepts were spread across the Application layer instead of being named once.

**Authorisation.** Every authenticated use case held both the `BlossomAuthValidatorInterface` and the `BlossomPolicyInterface` and wrote out the same sequence itself: `parse()`, the request-bound checks (the `x`-tag binding or scope and the pre-flights' declared-value guards), `verify()`, then the policy (ADR-0026, with the gated read's version in ADR-0014 and ADR-0015). Eight use cases restated that order, each with its own copy of the fence comment, and `BlobValidator` held the validator for the one binding it applies to the staged bytes. The order is a security property: a use case that asked the policy before `verify()` would reopen the tenant oracle ADR-0026 closed, and nothing but review stopped a ninth use case from doing so.

**Storage.** Three units held both `BlobStoreInterface` and `BlobIndexInterface` and each re-expressed an invariant between them. `BlobIngestor` stored the bytes and then indexed them, so a reader never finds an index entry whose bytes are missing; `GetBlobUseCase` read the index and then the store to resolve a hash; `BlobRemover` dropped a tenant's entry and released the bytes only when no tenant still indexed the hash (ADR-0011). The pair is one aggregate, content-addressed bytes plus the per-tenant entries that reference them, but no type said so.

Carrying both pairs separately pushed five constructors to four collaborators (`BlobIngestor`, `CheckMediaUseCase`, `GetBlobUseCase`, `MirrorBlobUseCase`, `OptimiseMediaUseCase`): two collaborators for each one concern.

## Decision

**`BlossomAccessGate`** (validator, policy) is the one way the Application layer authorises a request. It has one method per authorised request shape, and the order lives once, in its private `authorise()`: the parse result first, then the request-bound checks, then `verify()`, then the policy.

- `authoriseUpload(header)` and `authoriseMedia(header)` return the verified token or a failure; upload, mirror and optimise hand that token to the ingestor.
- `authoriseDelete(header, hash)` binds the `x` tag before verifying and returns the token, whose key the delete is scoped to.
- `authoriseUploadCheck(header, hash, declaredRefusal)` and `authoriseMediaCheck(…)` serve the BUD-06 pre-flights. The declared-value guards stay in the use case, because they read the upload constraints and the optimiser the gate does not hold; the use case passes them in as a closure, and the gate runs it after a successful parse and before the binding, exactly where the guards ran before. A closure rather than a precomputed verdict, so that an unparsable request never reaches the guards or the optimiser port behind them. They return `?BlossomFailure` because a pre-flight needs no token.
- `authoriseList(header, target)` returns `?BlossomFailure`; listing needs no token.
- `authoriseGet(header, hash)` owns the gated read whole: it asks `allowGet(null, hash)` first, so a read the policy opens to everyone never parses a header, and only when that refuses and a header is present does it parse, scope, verify and ask `allowGet(actor, hash)`.
- `authoriseReceivedBlob(token, hash)` is the write pipeline's binding of the staged bytes to an already verified token (ADR-0006). It cannot precede `verify()`, since the hash exists only once the bytes do; `BlobValidator` reaches it through the gate so that no Application class but the gate consumes the validator.

Methods return the token only where the caller uses it, so no caller narrows a token it then discards.

**`BlobArchive`** (store, index) owns the pair.

- `store(tenant, tempPath, descriptor)` is a two-phase write: the bytes are stored, then the index names them. If indexing throws, the archive releases the bytes when no tenant indexes the hash and rethrows the fault unchanged, so a failed write leaves no orphan and a host still sees the fault (ADR-0002).
- `discard(tempPath)` drops a staged file; the ingestor keeps ownership of when (ADR-0004).
- `retrieve(hash)` resolves a hash to a `RetrievedBlob`, or to `BlobNotFoundFailure` when the index has no entry or the store has no bytes.
- `removeForTenant(tenant, hash)` is ADR-0011's reference-counted delete. It absorbs `BlobRemover`, which would otherwise have become a class forwarding one call to the archive.

`ListBlobsUseCase` keeps `BlobIndexInterface`, because a listing is an index query that never touches bytes, and routing it through the archive would add a forwarding method and nothing else. `TenantOwnedBlossomPolicy` keeps the index for its ownership read (ADR-0008).

Neither class has an interface. Each is an internal collaborator built from two seams that are already interfaces, which is where a test or a host substitutes behaviour, the same standing as `BlobIngestor` and `BlobValidator`.

## Consequences

- Every Application constructor takes at most three collaborators: `BlobIngestor` (validator, descriptor factory, archive), `GetBlobUseCase` (archive, gate), `DeleteBlobUseCase` (archive, gate), `CheckMediaUseCase` (optimiser, gate, constraints), `MirrorBlobUseCase` and `OptimiseMediaUseCase` (port, gate, ingestor), `CheckUploadUseCase` (gate, constraints), `ListBlobsUseCase` (index, gate), `UploadBlobUseCase` (gate, ingestor), `BlobValidator` (inspector, constraints, gate).
- The authorisation order is stated and fenced once. `BlossomAccessGateTest` records the calls each method makes and pins the order per verb: a failed parse stops everything, a request-bound refusal stops before `verify()`, an invalid signature never reaches the policy, and the public read never parses a header. The use-case tests still pin the observable results end to end.
- The pre-flights' declared-value guards run where they ran before: after `parse()`, before the binding and `verify()`. `CheckMediaUseCase` asks `MediaOptimiserInterface::supports()` only once the header parses; `BlossomAccessGateTest` pins that an unparsable header never runs them. What the guards reveal is what `HEAD /upload` and `HEAD /media` exist to tell any client (BUD-05, BUD-06), so their answer before verification discloses nothing about the key.
- `BlobValidator` depends on a gate that also holds the policy, which it never asks. That is wider than the validator seam ADR-0021 described, and is accepted so that authorisation has one entry point; ADR-0021's decision itself, one cohesive validator contract, stands, and the gate is now its only Application consumer.
- An index fault after the bytes are stored no longer strands them; the release follows ADR-0011's rule, so bytes another tenant still indexes are kept. The ingestor builds the descriptor before storing, which moves the upload timestamp's clock read ahead of the store call; nothing observes the difference.
- A future reader must not have a use case call the validator or the policy directly, nor give a class the store and the index side by side again. Either reintroduces a second place where the order or the store-index invariant can drift.
