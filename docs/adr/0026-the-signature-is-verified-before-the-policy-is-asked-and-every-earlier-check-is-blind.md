# 26. The signature is verified before the policy is asked, and every earlier check is blind to the key

## Status

Accepted

Supersedes ADR-0003, which ran policy admission before signature verification and accepted the tenant oracle that ordering opens, and ADR-0019, which listed admission among the checks that precede verification. The rest of ADR-0019, that every check reading only the caller's own request and the limits the server advertises runs before verification, is carried forward below unchanged. ADR-0014 and ADR-0015 are unaffected: the gated read already verified before its policy check, and this record makes that the rule on every path.

## Context

Every authenticated use case does four things with a kind-24242 authorisation event: `parse()` runs the envelope checks (kind, verb, `server` scope, `created_at`, expiration), a set of request-bound checks runs (the `x`-tag binding or scope, the pre-flights' declared size and type guards), `verify()` checks the secp256k1 signature, and the policy decides whether this pubkey may act. Signature verification is the one expensive step, so the order of the rest decides how much work an unwanted request can make the server do.

ADR-0003 put the policy first to save that work, and accepted the cost: an unauthenticated caller who names a trusted key in an unsigned token is refused later (at verification, 401) than one who names an unknown key (at admission, 403), so the refusal tells it which keys the operator trusts. ADR-0003 judged that harmless because tenant keys sign public events. That judgement was wrong on two counts. Not every key a policy trusts is one that advertises this server: an allow-list can name keys that never published a kind-10063 list pointing here, and a host's policy can be any function of the key, not just the shipped tenant set. And a library cannot know what its host's policy treats as private, so it cannot promise that revealing the answer is harmless.

innis/nostr-relay settled the same question the other way (its ADR-0018): the relay verifies an AUTH signature before it asks the policy about the key, so a refusal never reveals whose keys are trusted to someone who has proved nothing. The two libraries should not judge the same disclosure differently.

BUD-11 separates the two refusals by status. BUD-01, BUD-02, BUD-04 and BUD-06 each define `401 Unauthorized` as "Authorization is required and missing or invalid" and `403 Forbidden` as "The request is understood but not allowed by server policy". An invalid signature is an invalid authorisation, so it is a 401 whoever the key belongs to; only a caller who has proved the key is theirs reaches the policy and can be told 403.

What ADR-0019 established about the remaining checks still holds. Whether a check may run before the caller has proved key possession is decided by what it reads. The envelope checks, the `x`-tag binding and scope, and the declared-value guards are functions of the caller's own request and of limits BUD-06 requires the server to advertise, so their answer is the same whichever key signed the token. They reveal nothing about the key, and running them first keeps a malformed request from costing a curve operation.

## Decision

In every authenticated use case:

1. `parse()` runs first, and after it every check that reads only the caller's own request and the limits the server advertises: the `x`-tag binding or scope and the declared size and type guards.
2. `verify()` runs next. No check before it reads the policy, the index, or anything else whose answer depends on which key signed the token.
3. The policy is asked only after `verify()` succeeds, and any read of private state and any side effect come after that.

Concretely: upload, mirror and optimise run parse → `verify` → policy → stage and ingest; list runs parse → `verify` → policy → list; delete runs parse → `x` binding → `verify` → policy → remove; the pre-flights run parse → declared guards → `x` binding → `verify` → policy; a gated read runs the anonymous `allowGet(null, …)` gate, then parse → `x` scope → `verify` → `allowGet(actor, …)` → retrieve. The report use case is unchanged (ADR-0010): it has no policy.

## Consequences

- Every refusal a caller can reach before its signature is verified is computed without reference to the key, so an invalid signature from a trusted key and from an unknown key receive the same refusal, `AuthenticationFailure::invalidSignature()`, a 401. Only a caller who holds the key learns what the policy says about it. The use-case tests pin this per use case: an invalidly signed token from a trusted key and from an unknown key produce equal results, the unknown key's is an authentication failure, and a validly signed unknown key is still refused by the policy.
- The accepted cost is CPU: a validly formed request from a key the policy would refuse now pays one secp256k1 verification before the refusal. That is the same trade innis/nostr-relay makes, and a request that fails any cheap check still never reaches verification.
- Failure precedence at the margins is as ADR-0019 left it: an invalidly signed pre-flight declaring an oversize blob is refused as too large (413), not as unauthenticated. That refusal is computed from the caller's own declaration and the advertised limit, so it discloses nothing about the key.
- The ingest paths' binding against the staged or fetched bytes still follows verification, because the hash does not exist until the bytes do; that is I/O sequencing, not an exception.
- A future reader must not move the policy back ahead of `verify()` to save the curve operation, and must not move any check whose answer depends on the key ahead of it.
- Shared decision: nostr-adrs ADR-0028.
