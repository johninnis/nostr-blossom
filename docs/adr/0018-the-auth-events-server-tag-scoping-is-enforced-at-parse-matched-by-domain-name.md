# 18. The auth event's `server`-tag scoping is enforced at parse, matched by domain name

## Status

Accepted

## Context

BUD-11 lets a client scope a kind-24242 authorisation token to specific servers: a `server` tag carries a lowercase domain name (`cdn.example.com`, not a full URL), multiple tags widen the set, and *"If one or more server tags are present, the server MUST verify that its domain name appears in at least one server tag."* A token with no `server` tags is valid for all servers.

Without the check, a token a client deliberately minted for one server is accepted by every server it leaks to — the same widening-of-a-narrowed-token failure the `x`-tag scoping of ADR-0015 closes for blobs, here across servers. Two aspects of the enforcement could read as wrong to a later reader:

- **It runs inside `parse()`, not per use case.** The scoping is a property of the token envelope, independent of verb — every authenticated endpoint inherits it — so checking it once beside the kind/verb/expiration checks is the only single-codepath home. This is also why `BlossomAuthValidator` takes a `ServerIdentity`: the validator must know which server it is speaking for.
- **Matching is by domain name, compared case-insensitively, ignoring scheme, port, and path.** That is BUD-11's rule verbatim (the tag value is a domain, not a URL), and DNS names are case-insensitive by definition, so the case-fold is domain semantics rather than leniency. The comparison is `ServerIdentity::isThisServer()`, against the configured base URL's host.

## Decision

`BlossomAuthValidator::parse()` rejects an event whose `server` tags exist but do not name this server's domain, returning `AuthorisationFailure::serverNotAuthorised()`. An event with no `server` tags passes. The check runs with the other cheap envelope checks, before any signature verification.

## Consequences

- A leaked token scoped to another server is worthless here, and a token scoped to this server is worthless elsewhere (on servers that conform) — token scoping now means what the client intended, across both of BUD-11's scoping axes (`server` tags here, `x` tags in ADR-0015).
- `BlossomAuthValidator` gained a `ServerIdentity` constructor dependency; a host passes the `ServerIdentity` it already holds (via `ServerConfig::getIdentity()`).
- A future reader must not move this check into the use cases (it would be repeated per verb and forgotten by the next one), nor "tighten" the match to a full-URL or origin comparison — the tag value is a bare domain by spec, so comparing anything more rejects conformant tokens. `BlossomAuthValidatorTest` pins the matching, the multi-tag admit, the case-insensitivity, and the untagged pass-through.
