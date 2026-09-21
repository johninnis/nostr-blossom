# 0006. The upload/delete authorisation event must name the blob (`x` tag required)

## Status

Accepted

## Context

A kind-24242 event carries a verb (`t`) and an expiration; the `x` (blob hash) tag binds the authorisation to a specific blob. Treating it as optional means an authorised pubkey's event for verb `upload` would authorise storing *any* bytes, not only the bytes it named. When this record was written BUD-02 phrased the `x` tag as a *SHOULD*, and this package chose to require it regardless; the spec has since been restructured and BUD-11's endpoint table now marks the tag *required* for upload, mirror, media, and delete — so the decision below, once stricter than the spec, is now what the spec mandates.

## Decision

Every write path requires the binding, enforced by one check — `BlossomAuthValidator::requireAuthorisedBlob`, which returns `AuthorisationFailure::blobNotAuthorised` unless the auth event carries an `x` tag matching the hash it is given. The write pipeline calls it (through `BlobValidator`) on the uploaded and mirrored bytes; on the media path it binds the **pre-optimise original** the client submitted, not the derived output that is stored — the optimiser's output hash cannot be known in advance, so the client signs for the input it asked to optimise (see ADR-0004, ADR-0012); `DeleteBlobUseCase` calls it for the hash being deleted; and the `HEAD /upload` and `HEAD /media` pre-flights (`CheckUploadUseCase`, `CheckMediaUseCase`) call it on the client's declared hash, so a pre-flight and its later `PUT` bind identically.

The gated *read* is the deliberate exception: a `get` token's `x` tags are optional and only *scope* the token when present, rather than being required — see ADR-0015.

## Consequences

- An authorised pubkey can never store or delete a blob its signed event did not name. On upload, mirror, and delete the named hash is the acted-on blob itself; on the media path it is the original the client submitted, whose optimised output the server derives and stores. Either way the signed authorisation is bound to specific content the client named, not to a blanket capability.
- A client that omits the `x` tag on a write is rejected. This is pinned by the ingestor, delete, and pre-flight use-case tests; because the check lives in one method, every write and pre-flight path enforces it identically and a new write path inherits it by calling the same method.
- Should BUD ever relax the tag back to optional on a write verb, this record must be superseded before the binding is loosened — the content-binding guarantee is the package's, not the spec's to give back.
