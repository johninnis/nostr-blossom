# 12. The inspector exposes two methods, `inspect` and `inspectWithMedia`, so the pre-optimisation original is hashed without a media decode

## Status

Accepted

## Context

A host's `BlobInspectorInterface` reports a staged blob's content hash, byte size, and detected MIME type, and — when it decodes the bytes as an image — pixel dimensions and a blurhash (`MediaMetadata`, which becomes the descriptor's NIP-94 `dim`/`blurhash`). Decoding the image is the expensive part: the cheap fields come from a hash and a `stat`; dimensions and blurhash require fully decoding the pixels.

On the upload and mirror paths the bytes inspected are the bytes stored, so one inspection serves both the guards and the descriptor's media tags. The media-optimisation path (BUD-05) is different: the bytes that get stored are the optimiser's *output*, not the client's original. The tempting reading is therefore "don't inspect the original at all — inspect only the output." That is wrong, because the original's cheap fields are load-bearing:

- **The authorisation binding is signed against the original.** A BUD-05 client cannot know the optimised hash in advance — the server produces it — so it puts the *original* blob's hash in the auth event's `x` tag. Verifying that binding (ADR-0006) requires hashing the original; there is nothing else to check the `x` tag against.
- **The descriptor records the pre-optimisation hash as `ox`** (NIP-94 `originalHash`), which is again the original's hash.
- **The size guard must reject an over-size upload before the optimiser runs on it**, not after paying to re-encode it.

So the original must be inspected for its hash and size. What it must *not* get is a media decode: its pixel dimensions and blurhash would describe the wrong bytes — the descriptor's media tags come from the stored output, inspected separately. A single always-decoding `inspect()` would force a full pixel decode of the original for metadata that is not merely unused but semantically wrong to attach, and which the optimiser decodes again when it re-encodes. A boolean `inspect(string $path, bool $withMedia)` would carry the same intent, but a flag that switches a method between two jobs reads worse at the call site than two named methods.

## Decision

`BlobInspectorInterface` exposes two methods. `inspect()` returns the cheap fields only (hash, size, detected type). `inspectWithMedia()` additionally decodes the bytes and attaches `MediaMetadata`. The ingestor calls `inspectWithMedia()` for the bytes it will store (the upload, the mirror fetch, the optimiser's output) and `inspect()` for the pre-optimisation original — whose hash anchors the authorisation binding and the `ox` field, but whose pixels never reach a descriptor.

## Consequences

- The original is hashed once and never pixel-decoded; the only image decode of the original is the one the optimiser must perform anyway to re-encode it.
- "Why inspect the original at all?" is answered by the authorisation model: the `x`-tag binding and the `ox` field are both the original's hash, so the original must be inspected — just not for media. Its hash and size are consumed; only a media decode is declined.
- The two methods are not redundant: they answer different questions at different costs, and which one a path calls is chosen by whether the inspected bytes survive to be stored.
- A host implements both. `inspectWithMedia()` is a strict superset of `inspect()`, so an implementation that cannot cheaply skip the decode may have `inspect()` delegate to it and drop the metadata — at the cost this decision exists to avoid.
- A future reader must not "simplify" the two methods into one always-decoding `inspect()`, nor into a boolean-flag variant, nor drop the original's inspection. The optimise use-case tests pin that the original is inspected via `inspect()` and only the stored output via `inspectWithMedia()`.
