# 25. A pending-blob source may answer with a failure

## Status

Accepted

## Context

`PendingBlobSourceInterface` is the port through which a host hands the upload and media use cases the request body: the use case authenticates and admits the caller first, then asks the source to stage the body to a temp file, then ingests the staged file (ADR-0004). Until now `stage()` returned a `PendingBlob` and nothing else, so anything that stopped staging short surfaced as a thrown fault that left the use case unhandled.

One thing that stops staging short is anticipated, not broken: the body is larger than the server accepts. A host that streams the body to disk must stop reading at the size cap, because buffering a hundred megabytes in order to measure it afterwards is not an option, and the library's own size guard runs only after the inspector has measured a fully staged file (ADR-0005). The host therefore knows the outcome before the library can, and the port gave it no way to say so. The refusal escaped as a transport exception and reached the client as whatever the host's error handler makes of an unknown fault, rather than as the same `413` with the same reason that every other refusal in this library produces.

The alternatives were to catch the fault in the use case and report an unreadable blob, which gives a `500` for what is a client error, or to leave the host to catch it in its presentation layer after the use case has already authenticated and verified the caller, which is a fault caught halfway through a unit that had no part in raising it.

## Decision

`stage()` returns `PendingBlob|BlossomFailure`. A source that stops reading at the size cap returns `BlobTooLargeFailure::beyondMaximum($max)`, the factory added for it, which names only the maximum because a stream cut at the cap has no true size to report. The upload and media use cases return the failure as they return any other, before anything is ingested.

A source that cannot stage for a reason that is not anticipated, a disk that will not write or a client that disconnects mid-body, still throws. The failure value is for the "no" the host can foresee, not a catch-all.

## Consequences

- A host can refuse an oversize body while streaming it and have the refusal reach the client as a `413` carrying the failure's message, identical to a refusal of an oversize declaration on the preflight.
- The library's post-inspection size guard is no longer the check that fires on a streaming host, because the source cuts first. The guard stays: it is what protects a host that stages without a cap, and it is the check behind the `HEAD` preflight.
- Nothing is ingested and no temp file exists when the failure is returned; the source has already cleaned up what it started, so the ingestor's temp-file ownership (ADR-0004) begins only with a staged blob.
- A host implementing the port must handle the case where the body is over its cap by returning the failure, not by throwing. Pinned by `UploadBlobTest::testReturnsTheSourceFailureWithoutIngesting` and `OptimiseMediaTest::testReturnsTheSourceFailureWithoutIngesting`.
