# 0015. A gated get honours the auth event's `x`-tag scoping without requiring it

## Status

Accepted

## Context

ADR-0006 promotes the `x` (blob hash) tag to a *MUST* on every write path: an upload, mirror,
optimise, or delete is rejected unless its authorisation event names the exact hash being acted
on. The gated read is different. BUD-11 marks the `x` tag **optional** on a `get` authorisation
event — a client may mint one token for all its reads — but adds a scoping rule: *"If x tags are
present, the token is only valid for operations on the specified blob hashes."*

That leaves three candidate behaviours for a gated `GET`:

1. **Ignore `x` tags entirely.** Simple, but it silently widens a deliberately narrowed token: a
   get token scoped to blob A also fetches blob B, so a leaked or intercepted scoped token is a
   server-wide token. It also violates BUD-11's scoping rule outright.
2. **Require a matching `x` tag**, mirroring ADR-0006. Consistent-looking, but it rejects the
   spec-legitimate tag-less get token and would force clients to mint one token per blob for
   ordinary gallery-style reads.
3. **Honour the scoping when present, require nothing when absent** — exactly BUD-11's rule.

A reader who has just read ADR-0006 will see the third behaviour as an inconsistency to "fix" in
either direction: deleting the check (back to 1) or promoting it to a required binding (to 2).

## Decision

The gated read takes the third behaviour. `BlossomAuthValidator::requireBlobInScope()` returns
`null` when the event carries no `x` tags or when one of them matches the requested hash, and
`AuthorisationFailure::blobNotAuthorised()` when `x` tags are present but none matches.
`GetBlobUseCase` runs it on the gated path only, sequenced `parse` → scope → `verify` → policy:
the scope check reads nothing but the caller's own submitted event, so per ADR-0003 it runs
before the expensive signature verification, while ADR-0014's verify-before-policy ordering is
untouched.

`requireAuthorisedBlob()` (the ADR-0006 *MUST* binding) and `requireBlobInScope()` stay two
named methods, not one method with a flag: the write verbs require the tag, the read verb only
honours it, and each call site says which rule it applies.

## Consequences

- A scoped get token is only good for the blobs it names: leaking it no longer leaks the rest of
  the server. A tag-less get token keeps working, as BUD-11 intends.
- A mis-scoped token is rejected before secp256k1 runs and before any policy index read.
- The asymmetry with ADR-0006 is deliberate: writes bind to named content because the client
  always knows the hash it is storing or deleting; reads may legitimately be token-per-session.
  A future reader must not "unify" the two methods into one required binding, nor drop the scope
  check as redundant beside the policy — the policy decides *who* may read, the scope decides
  *what this token covers*. `GetBlobTest` pins both the scoped rejection and the tag-less admit.
