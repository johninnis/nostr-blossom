# 0021. The kind-24242 auth validator is one cohesive contract, pure binding checks included

## Status

Accepted

## Context

`BlossomAuthValidator` answers four questions about a kind-24242 authorisation
event, behind a single `BlossomAuthValidatorInterface`:

- `parse()` — decode the header and run the envelope checks (kind, verb, `server`
  scope, `created_at`, expiration). Needs the clock and the server identity.
- `verify()` — check the secp256k1 signature. Needs the signature service.
- `requireAuthorisedBlob()` / `requireBlobInScope()` — the `x`-tag binding and
  scoping checks (ADR-0006, ADR-0015). These read only the event's own tags and a
  `BlobHash`; they touch **none** of the three injected collaborators.

Two facts about that shape read like a smell, and a reviewer will be tempted to
"fix" them:

1. Two of the four methods are pure functions sitting on a class that holds
   infrastructure collaborators. The obvious move is to extract them into a
   dependency-free domain service, leaving `BlossomAuthValidator` with only the
   port-backed authentication.
2. The one interface therefore mixes port-backed methods with pure ones. Interface
   segregation says split it, so a consumer that needs only the binding check does
   not depend on the signature service behind the rest.

The pull is real because `BlobValidator` consumes *only* `requireAuthorisedBlob()`
— it never authenticates — so on paper it depends on more than it uses.

## Decision

Keep the four methods on one `BlossomAuthValidator` behind one
`BlossomAuthValidatorInterface`. Do not extract the binding checks into a separate
service, and do not segregate the interface into port-backed and pure roles.

The binding checks are questions asked *of a validated authorisation event*: "does
this token name this blob", "does this token's scope cover this blob". That they
happen to need no clock or signature service is incidental — they belong to the
same concept as parsing and verifying the token, and a reader looking for
"everything this package decides about an auth event" finds it in one place.

`BlobValidator`'s over-wide dependency is closed by the seam, not by a split: it
depends on the `BlossomAuthValidatorInterface` abstraction, and its unit test
substitutes a stub with no signature service in sight. The interface already buys
the substitutability and the test isolation that extraction would be reached for.

## Consequences

- Extraction was measured against the real cost and rejected. The binding checks
  are called from `BlobValidator` and four use cases (`DeleteBlobUseCase`,
  `CheckUploadUseCase`, `CheckMediaUseCase`, `GetBlobUseCase`), every one of which
  already holds the validator to `parse`/`verify`. A separate binding collaborator
  would be a *second* dependency threaded through all of them — pushing
  `CheckMediaUseCase` and `GetBlobUseCase` to five collaborators each — to relocate
  a check that reads one line of the event. The cohesion is worth more than the
  layering purity, the same trade ADR-0009 makes in keeping one policy interface
  rather than a port per verb.
- Splitting the interface for one narrow consumer would multiply the auth contract
  a host implements, for a purity gain the seam already delivers: `BlobValidator`
  is unit-tested against a stubbed `BlossomAuthValidatorInterface` today, with no
  real crypto.
- A future reader must not extract `requireAuthorisedBlob()` /
  `requireBlobInScope()` into a standalone service, nor segregate the interface,
  on the argument that they use no ports. The whole-contract shape is pinned by
  `BlossomAuthValidatorInterface` and exercised by `BlossomAuthValidatorTest`
  (the real binding logic) and `BlobValidatorTest` (the stubbed consumer).
