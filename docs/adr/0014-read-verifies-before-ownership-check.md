# 14. A gated read verifies the signature before the authorising policy check

## Status

Accepted

## Context

ADR-0003 sequences every authenticated action as `parse` → policy admission → `verify` → act, so an unadmitted caller is rejected without paying secp256k1. That ordering is sound when admission is a pure function of `(actor, TenantPubkeys)`: the actor's pubkey is public, so revealing "admitted vs not" before verification leaks nothing an attacker could not already read.

The read path breaks that assumption. `TenantOwnedBlossomPolicy` (ADR-0008) resolves `allowGet` by asking the index whether the actor owns the blob — a read of private per-tenant state, not a pure function of public configuration. When that check runs during admission, before the signature is verified, it becomes an oracle:

- An attacker forges an unsigned kind-24242 event naming a *known* tenant pubkey (public per ADR-0003) and a candidate hash. `parse` accepts it — it does not verify signatures.
- Admission runs the ownership read. If the tenant owns the hash, `allowGet` admits and the request fails later at `verify` (401); if not, `allowGet` denies immediately (403).
- The attacker distinguishes 401 from 403 and learns whether that tenant holds that blob — the exact association the tenant-owned mode exists to hide — without ever producing a valid signature.

The write verbs do not have this problem: their admission is the pure tenant check, and any private read (the actual `list`, the delete) already happens after `verify`. Only the read path folds a private read into admission.

## Decision

On the gated read path, `GetBlobUseCase` verifies the signature **before** consulting the authorising `allowGet(actor, hash)`. The anonymous gate `allowGet(null, hash)` still runs first and still short-circuits a public read without parsing or verifying anything, so the high-volume public case never pays secp256k1. Only a caller that has *already presented an Authorization header* — i.e. one asking for gated access — has its signature verified before the ownership-bearing policy check.

## Consequences

- The ownership oracle is closed: `allowGet` (and therefore any `BlobIndexInterface::ownsBlob` read behind it) runs only after the caller has proved possession of the signing key. A caller can only ever learn its *own* ownership result, which it is entitled to.
- This refines ADR-0003 for the read path only. ADR-0003 still governs the write verbs (upload, media, delete, list), whose admission reads no private state, and it still governs the public read, which never reaches `verify`. The CPU-admission optimisation is therefore preserved everywhere it guards public data.
- The accepted cost is narrow: on an instance configured with a non-public `GetAccessPolicy`, a header-bearing caller that would be denied by the policy now pays one secp256k1 verification before that denial. This is confined to the opt-in gated modes and to callers who chose to authenticate; it is the right trade against disclosing private ownership to an unauthenticated probe.
- A future reader must not "restore" the ADR-0003 order on the read path for uniformity: the read's policy check reads private state, so verifying first is load-bearing. `GetBlobTest` pins that a gated read with an invalid signature is rejected without the ownership check being reached.
