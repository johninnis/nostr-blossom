# 0010. The report use case verifies the event signature directly, bypassing the auth validator and policy

## Status

Accepted

## Context

Every other authenticated path in this package runs the same sequence: `BlossomAuthValidator`
parses a kind-24242 authorisation event, a `BlossomPolicyInterface` admits the actor, then the
signature is verified (ADR-0003). `ReportBlobUseCase` (BUD-09) does not. It takes a raw JSON body,
parses it as a kind-1984 reporting event, checks the event names at least one blob (an `x` tag),
verifies the event's own signature, and persists the report.

A reader expecting the uniform pipeline will see this as an inconsistency to "fix" by routing the
report through `BlossomAuthValidator` and a policy. That would be wrong, because a report is a
different kind of request:

- A report carries **no kind-24242 `Authorization` header**. The thing being authenticated *is*
  the reporting event itself (kind-1984), not a separate auth envelope wrapping it. There is no
  envelope for `BlossomAuthValidator` to parse — it only understands kind-24242.
- A report is **not gated by tenancy**. BUD-09 lets anyone report a blob; the point is to collect
  abuse reports from the public, not from the server's tenants. There is no actor to admit against
  the allow-list, so there is no policy decision to make.

What the report *does* need is proof the reporting event is genuinely signed by the pubkey it
claims, so a report cannot be forged in someone else's name. That is a plain signature
verification on the event, which `Event::verify()` already provides.

## Decision

`ReportBlobUseCase` authenticates the report inline: it parses the body with `Event::tryFromJson`,
rejects a non-kind-1984 event and one with no resolvable blob `x` tag, and verifies the event's
signature directly with `Event::verify($signatureService)`. It does not use `BlossomAuthValidator`
and takes no `BlossomPolicyInterface`. Each failure returns a typed `BlobReportFailure`.

## Consequences

- The report path is honest about what it is: a self-signed kind-1984 event with no auth envelope
  and no tenancy gate. Forcing it through the kind-24242 validator or a policy would either fail
  (no envelope to parse) or add a tenancy check BUD-09 does not want.
- The cheap-checks-before-signature ordering of ADR-0003 still holds in spirit: kind and blob-tag
  checks run before the secp256k1 verification, so a malformed or blob-less report is rejected
  without paying the curve operation.
- A future reader must not "unify" the report path onto `BlossomAuthValidator`/`BlossomPolicyInterface`.
  The difference is intrinsic to BUD-09, not an oversight, and is pinned by the report use-case
  tests.
