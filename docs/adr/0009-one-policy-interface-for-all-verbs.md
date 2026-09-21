# 0009. One `BlossomPolicyInterface` covers every verb, not a port per verb

## Status

Accepted

## Context

Five authenticated actions need an authorisation decision: upload, media, list, delete, and get. Clean architecture would let each be its own port — `UploadPolicyInterface`, `DeletePolicyInterface`, and so on — so a use case depends only on the one decision it makes, and a host implements only the verbs it serves.

In practice the decisions are not independent. The write verbs (`upload`, `media`, `delete`) share one rule: the actor must be a configured tenant. `list` adds an own-blobs check on top of that tenant rule. `get` is the read rule of ADR-0008. A host that admits a pubkey for upload almost always admits it for the other writes too; splitting the verbs across five ports would force a host to repeat the same tenant check in three separate implementations, or to build its own shared base behind the ports — re-aggregating what the split pulled apart.

## Decision

A single `BlossomPolicyInterface` answers "may this actor perform this action on this blob" for every verb: `allowUpload`, `allowMedia`, `allowList`, `allowDelete`, and `allowGet`. Each method returns `?BlossomFailure` (null admits). One host object implements all five.

## Consequences

- A host writes its authorisation rules in one class and reuses a private tenant check across the verbs that share it, rather than threading a shared base through five ports. The ready-made `TenantBlossomPolicy` does exactly this.
- A use case still depends only on the methods it calls — the interface is the seam — but there is one seam to learn, not five.
- The cost is that a host implementing the interface must supply all five methods even if it serves only some verbs; the unused ones are trivial (`return null` or a blanket denial). This is cheaper than the per-port alternative's shared-base boilerplate.
- A future reader must not split this into per-verb ports: the verbs share authorisation machinery, and the split would scatter that machinery or force a host base to re-aggregate it.
