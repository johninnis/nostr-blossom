# 4. The write pipeline: a validator, a descriptor factory, and a coordinator that owns the temp file

## Status

Accepted

## Context

All three write paths — upload, mirror, and optimise — funnel through one write pipeline that verifies a blob (readable, within size, authorised, an allowed *stored* type) and persists it. Left as a single `BlobIngestor`, that unit did two jobs at once — decide whether bytes may be stored, and store them — and needed six collaborators to do so (inspector, config, auth validator, store, index, clock). Three details of the shape need justifying.

1. Only the optimise path needs a `MediaOptimiserInterface`; upload and mirror never touch one. Constructor-injecting it would force every host to supply an optimiser even when wiring only the upload and mirror flows — a dependency the type demands but those paths ignore.
2. "Deciding storability" and "persisting" are two responsibilities. A single class doing both carries every collaborator either needs, and its guard logic cannot be tested without also standing up a store double it never calls on the success path.
3. The temp-file path is fragile to spread around: whichever unit runs a guard could be tempted to discard the file itself, and on the optimise path the *live* file changes mid-pipeline (the original is dropped the moment the optimiser emits a new file), so a discard at the wrong place cleans up the wrong bytes.

## Decision

The write pipeline is three cohesive units, composed:

- **`BlobValidator`** (inspector, config, auth validator) decides storability. It inspects the staged bytes, applies the guards (allowed *stored* type, size, declared-hash match, and the `x`-tag authorisation binding), and returns a `StorableBlob` or a `BlossomFailure`. It is **side-effect-free** — it never stores or discards — so its rules are testable without a store double. It exposes `validateStorable()` for the upload/mirror bytes, `admitOriginal()` for the pre-optimise original (size and binding only, no allow-list — see ADR-0012), and `validateProduced()` for the optimiser's output.
- **`BlobDescriptorFactory`** (config, clock) assembles the wire descriptor for a stored blob: the URL, the upload timestamp, and the BUD-08 `nip94` tags (see ADR-0012 for `ox`). Pure construction, no I/O.
- **`BlobIngestor`** (validator, descriptor factory, store, index) is the coordinator. It owns the temp file's lifecycle end to end, sequences the optimise interleaving, and persists.

The optimiser is a **method argument** to `ingestOptimised()`, not a constructor dependency. `OptimiseMediaUseCase` holds the host-wired port and hands it in; the upload and mirror use cases construct the same ingestor with no optimiser. Every constructor field is then used by every path.

The temp-file lifecycle lives **only in the coordinator**. The validator returns a failure without touching the file; the coordinator discards whichever file is live at the point of failure, and discards the original the moment the optimiser emits a replacement.

## Consequences

- Each unit has one reason to change: validation rules, descriptor/`nip94` format, or the storage pipeline. The coordinator drops from six collaborators to four, and the guard logic is unit-testable with no store in sight.
- The optimiser stays out of the upload/mirror constructors: no path-specific collaborator masquerading as a shared one.
- Centralising discard in the coordinator is load-bearing, not incidental. The live temp file *changes* mid-pipeline: on the optimise path the original is discarded when the optimiser emits a new file, and from that point a later failure must clean up the optimiser's output, not the original. Because the single owner tracks which file is current, no guard needs the path threaded through it.
- A future reader must not re-merge the validator or the descriptor factory back into the coordinator to "save a class", nor let a guard discard its own file: the ingestor tests assert that the produced file (not the original) is discarded when a downstream step fails after the optimiser emits a new file, that upload/mirror wiring needs no optimiser, and `BlobValidator` is verified with no store double at all.
