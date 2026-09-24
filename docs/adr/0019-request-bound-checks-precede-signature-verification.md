# 19. Every request-bound check precedes signature verification

## Status

Accepted

## Context

secp256k1 signature verification is the single expensive step in every authenticated action. ADR-0003 put the cheap policy admission before it, so an unadmitted caller cannot make the server pay a curve operation; ADR-0015 did the same for the gated read's `x`-tag scope check. But two paths still paid the curve operation before checks that are just as cheap: the delete verified the signature before checking the auth event's `x`-tag binding, and the upload pre-flight verified it before the declared size, type, and binding guards — guards whose inputs are the caller's own headers and limits BUD-06 requires the server to advertise publicly.

The inconsistency is not just wasted CPU. A rule that holds on three paths and silently not on two others is a rule a future reader cannot trust: each new use case guesses its ordering.

Whether a check may run before the caller has proved key possession is decided by what the check reads. The binding, scoping, and declared-value guards are pure functions of **the caller's own request and limits the server advertises**, so running them early discloses nothing the caller does not already hold. Admission is the one early check that discloses anything — whether a pubkey is a configured tenant — and that disclosure is exactly the trade-off ADR-0003 already weighed and accepted. A check that reads **private server state** (per-tenant ownership, the index) is the opposite case, and ADR-0014 already requires verification first there.

## Decision

In every authenticated use case, all checks that read only the caller's own request and public configuration — policy admission, verb/kind/expiration envelope checks, `x`-tag binding and scoping, declared-value guards — run **before** `verify()`. Signature verification runs last among the checks, immediately before any private-state read (ADR-0014) or side effect.

Concretely: the delete runs admission → `x`-binding → `verify` → remove, and the pre-flights run admission → declared guards → `x`-binding → `verify`.

## Consequences

- No request that fails a cheap check ever costs the server a curve operation, extending ADR-0003's CPU-exhaustion defence from admission alone to every request-bound check.
- Failure precedence changes at the margins: an invalidly-signed pre-flight with an oversize declaration now learns `413` rather than `401`. This discloses nothing — the response is computed from the caller's own declared values and configuration the server advertises.
- The write paths whose binding needs the staged or fetched bytes (upload, mirror, optimise) cannot hoist that binding — the hash does not exist until the bytes do — so on those paths `verify()` still precedes ingestion; that is I/O sequencing, not an exception to this rule.
- A future reader must not "restore" verify-before-guards for uniformity with the ingest paths, nor move a private-state read ahead of `verify()` (ADR-0014 governs that side). The orderings are pinned by the delete and pre-flight use-case tests asserting a cheap-check failure is returned even when the signature is invalid.
