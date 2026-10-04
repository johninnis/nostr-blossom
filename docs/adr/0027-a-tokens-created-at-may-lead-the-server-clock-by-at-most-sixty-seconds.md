# 27. A token's `created_at` may lead the server clock by at most sixty seconds

## Status

Accepted

Supersedes ADR-0016, which accepted a `created_at` up to one hour ahead of the server clock by reusing the general event plausibility window. The lower bound and the reasons for tolerating skew at all are carried forward; the upper bound is tightened to sixty seconds.

## Context

BUD-11's validation list says "The `created_at` timestamp MUST be in the past." Enforced literally, with no tolerance, the check refuses honest clients whose clock runs a few seconds ahead of the server's, which is the normal state of consumer devices. Every implementation of a "must be in the past" rule ends up tolerating some skew; the question is how much, and whether it is stated.

ADR-0016 answered with `Timestamp::isReasonableAt()`, which accepts anything up to one hour ahead. An hour is not skew. It lets a client sign a token that claims to be written well into the future, which the spec forbids, and nothing about clock drift needs that much room.

A bound for exactly this problem already exists in the family. NIP-98 HTTP authorisation, the other HTTP auth scheme this stack validates, allows a timestamp within sixty seconds of the server clock, and innis/nostr-core's `Nip98EventChecker` defaults to that tolerance. Sixty seconds is ample for real clock drift and short enough that "in the past" still means something.

BUD-11 states no lower bound on `created_at`; a token's life is bounded by its `expiration` tag, which it must carry and which is checked strictly (ADR-0023). So there is no spec basis for tightening the lower bound, and the existing one, ten years behind the server clock from the shared plausibility window (now innis/nostr-core's `CreatedAtWindow` default), is kept as a sanity check on absurd values.

## Decision

`BlossomAuthValidator::parse()` refuses, with `AuthenticationFailure::unreasonableCreatedAt()`, a token whose `created_at`:

- is more than sixty seconds ahead of the injected clock's `now()`, or
- is more than ten years behind it.

The check is one innis/nostr-core `CreatedAtWindow`, built with `secondsAhead: 60` and the shared default of ten years behind, asked whether it admits the token's `created_at` at `now()`. A `created_at` at or before `now + 60` and no more than ten years old is accepted. The sixty seconds is a class constant, not host configuration.

## Consequences

- The sixty-second tolerance is a deliberate, bounded departure from the letter of "must be in the past". It exists only to absorb clock skew between client and server, it is the same bound NIP-98 uses, and it does not extend a token's life, because the `expiration` tag, checked strictly, remains the real validity gate.
- A token claiming to be written more than a minute in the future is refused, where ADR-0016 accepted up to an hour.
- The check still runs in `parse()`, before signature verification. It reads only the token and the clock, so its answer is the same whichever key signed the token (ADR-0026).
- The upper bound is this package's own, stated beside the shared lower bound rather than replacing it, because the shared plausibility window's one-hour lead is too wide for an authorisation token while its lower bound is still wanted.
- A future reader must not widen the tolerance back to the plausibility window's hour, nor tighten it to zero. `BlossomAuthValidatorTest` pins acceptance at exactly sixty seconds ahead and refusal at sixty-one, against a fixed clock.
- Shared decision: nostr-adrs ADR-0097.
