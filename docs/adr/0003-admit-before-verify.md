# 0003. Authorisation admits before signature verification

## Status

Accepted

## Context

Every authenticated use case must both *authenticate* (the kind-24242 event is well-formed,
on the right verb, unexpired, and correctly signed) and *authorise* (the signing pubkey is
permitted this action). secp256k1 verification is the single expensive step. The naive order —
verify the signature, then check the policy — pays secp256k1 on every request, including those
from pubkeys the server would never admit.

Running the cheap policy admission first leaks one bit: an unauthenticated caller can probe
whether a given pubkey is a tenant, because an admitted-but-unsigned request fails later (at
`verify`) than an unadmitted one (at admission).

## Decision

Every authenticated use case runs `parse()` → policy admission → `verify()` → act. The
policy's tenant admission runs **before** signature verification, so an unadmitted caller is
rejected without ever paying secp256k1.

## Consequences

- An unadmitted request costs a header parse, not a curve operation — removing a cheap
  CPU-exhaustion vector.
- The accepted cost is the tenant-probe oracle described above. This is deliberate: tenant
  pubkeys are already public (they sign visible events and advertise this server in their
  kind-10063 Blossom server list), so there is no secret to protect, and paying secp256k1 on
  every unadmitted request would hand attackers the exhaustion vector to protect a non-secret.
- `parse()` and `verify()` are therefore two separate calls on `BlossomAuthValidator`, not one
  combined check. A future reader must not "tidy" them into a single verify-first step; the
  use-case tests assert that an unadmitted caller is rejected before signature verification.
