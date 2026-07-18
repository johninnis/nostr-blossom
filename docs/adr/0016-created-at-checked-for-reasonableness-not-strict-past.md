# 0016. The auth event's `created_at` is checked for reasonableness, not strictly "in the past"

## Status

Accepted

## Context

BUD-11's server validation checklist says the authorisation event's `created_at` *MUST be in the
past*. Enforced literally — reject any `created_at` greater than the server clock — the check
fails honest clients whose clock runs seconds ahead of the server's, which is the normal state
of consumer devices. Every deployed implementation of a "must be in the past" rule ends up
tolerating skew; the only question is whether the tolerance is explicit or an accident.

The event's `Timestamp` already carries a reasonableness predicate, `isReasonableAt(reference)`,
which accepts up to one hour in the future and ten years in the past relative to a supplied
reference instant. Reusing it means one shared definition of "a plausible event timestamp"
rather than a second, slightly different window maintained here.

## Decision

`BlossomAuthValidator::parse()` rejects an event whose `created_at` fails
`isReasonableAt($clock->now())`, returning `AuthenticationFailure::unreasonableCreatedAt()`.
The reference instant comes from the injected clock, so the check is deterministic under test.

## Consequences

- A pre-dated or far-future `created_at` is rejected during the cheap `parse()` phase, before
  any signature verification, closing the previously unchecked BUD-11 requirement.
- The accepted deviation from the spec's letter: a `created_at` up to one hour ahead of the
  server clock passes. The `expiration` tag — checked strictly — remains the real validity gate,
  so the tolerance does not extend a token's life; it only stops clock skew from breaking
  honest clients.
- A future reader must not "tighten" this to a strict past check without accounting for skew,
  nor hand-roll a second tolerance window instead of the shared `isReasonableAt`.
  `BlossomAuthValidatorTest` pins both the future and the ancient rejection.
