# 23. Any stated expiry that has passed expires the token

## Status

Accepted

## Context

BUD-11 requires a kind-24242 authorisation event to carry an `expiration` tag, and the server to reject a token whose expiry has passed. Nothing in the spec forbids an event from carrying that tag more than once, and nothing says which of several stated expiries wins.

Reading only the first `expiration` tag makes the verdict a function of tag order. A token carrying a future expiry followed by one that has already passed is accepted; the same two tags the other way round and the identical token is rejected. The order is part of the signed payload, so the signature does not constrain it — the signer chooses it. That hands the caller a free choice: state a long-lived expiry, append a short one, and the token is judged by whichever position the server happens to read. A short-lived token can be laundered into a long-lived one without ever touching the signature.

The same reading also lets a malformed value go unexamined: a well-formed expiry followed by garbage passes, because the garbage is never parsed.

There is a settled answer to the underlying question. The event type used here already defines expiry over *all* the expiration tags an event states — an event is expired once any expiry it names has passed — so the only reason this package saw a different answer was that it re-derived the check from a single tag instead of asking the event.

## Decision

The expiration check reads **every** `expiration` tag on the authorisation event:

- no `expiration` tag at all is `missingExpiration`,
- any stated expiry that is not a well-formed timestamp is `invalidExpiration`,
- otherwise the token is expired when **any** stated expiry has passed relative to the injected clock, which is the event's own `isExpiredAt()` judgement.

The presence requirement stays in this package rather than being delegated with the rest. `isExpiredAt()` answers "has any stated expiry passed", and an event stating no expiry is not expired by that definition. BUD-11 requires the tag, so its absence is this package's refusal, checked before the expiry judgement.

## Consequences

- The verdict no longer depends on tag order: the most restrictive expiry a token states is the one that binds, and a caller cannot extend a token's life by appending a later expiry or reordering the tags.
- The check is stricter than it was in a second way: a token whose second or later expiration is unparseable is now rejected as invalid, rather than accepted on the strength of its first. That is deliberate. Silently ignoring an unparseable expiry is the same order-sensitivity in another dress — it would mean a token's life depends on which of its malformed tags the server reached first.
- The three refusals stay distinguishable (`missingExpiration`, `invalidExpiration`, `expired`), so a host can still tell a client that never stated an expiry from one whose expiry lapsed.
- A future reader must not "simplify" this back to reading the first `expiration` tag, nor drop the parse guard on the strength of the expiry judgement tolerating a malformed value. Pinned by `BlossomAuthValidatorTest`'s `testRejectsTokenWhoseSecondExpirationHasPassed`, `testExpiryVerdictIsIndependentOfExpirationTagOrder`, and `testRejectsTokenWhoseSecondExpirationIsUnparseable`.
