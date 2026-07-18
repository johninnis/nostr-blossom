# 0008. Read access: an enum for the index-free modes, a decorator for tenant-owned

## Status

Accepted

## Context

Blossom reads are public by hash under BUD-01, but a host may want to gate them: to any
authenticated signer, to its configured tenants, or to a tenant only for blobs that tenant owns.
That is four distinct read rules, and they form a strict containment chain: `Public` admits
everyone; `Authenticated` adds a signed-event requirement; `Tenant` adds the same tenant allow-list
the write verbs already use; `TenantOwned` adds a per-blob ownership check on top of `Tenant`.

Three of the four are pure functions of `(actor, TenantPubkeys)` — they need nothing the policy does
not already hold. Only `TenantOwned` needs a new dependency: a `BlobIndexInterface` to answer "does
this tenant own this hash".

The obvious single-abstraction move is one `GetAccessPolicy` enum carrying all four cases on
`TenantBlossomPolicy`, with the index passed as an optional constructor argument that only the
`TenantOwned` arm reads. That looks clean until you inspect the type: the index is a dependency that
is **required in exactly one of four modes and meaningless in the other three**. Encoding that with a
nullable field forces a run-time constructor guard ("`TenantOwned` without an index throws") and a
downstream assertion at the read site — the analyser cannot tie "the mode is `TenantOwned`" to "the
index is non-null". Illegal states stay constructable (`TenantOwned` with no index; a `Tenant` mode
handed an index it ignores) and are caught only when first exercised, not by the type system.

## Decision

Split the four rules by whether they need the index.

The three index-free rules stay a `GetAccessPolicy` enum — `Public`, `Authenticated`, `Tenant` —
on `TenantBlossomPolicy`, defaulting to `Public`. `allowGet()` matches on the enum and reuses the
policy's existing tenant check for the `Tenant` arm. `TenantBlossomPolicy` holds no index and no
nullable dependency.

`TenantOwned` — "`Tenant` reads plus a per-blob ownership check" — is a **decorator**,
`TenantOwnedBlossomPolicy`, composing a `BlossomPolicyInterface` (a `Tenant`-mode policy) and a
**required** `BlobIndexInterface`. It delegates the four write verbs to the wrapped policy verbatim,
and its `allowGet()` runs the wrapped `Tenant` gate first, then the ownership check. Because the
index is a plain constructor argument, a tenant-owned policy without an index is **unconstructable** —
the misconfiguration the enum caught at run time is now a compile-time impossibility.

## Consequences

- No nullable dependency, no constructor throw, no read-site assertion. "Tenant-owned reads without
  an index" cannot be expressed, rather than being rejected the first time a read is gated. The type
  system carries what a run-time guard used to.
- The top link of the containment chain — `TenantOwned` = `Tenant` + ownership — is literal
  composition: the decorator delegates to a `Tenant`-mode policy and adds one check, rather than a
  fourth `match` arm reaching into a conditionally-present field.
- The tenant check is not scattered. The decorator reuses it by delegating to the wrapped policy, so
  there is still one `isTenant` admission, shared with the write verbs.
- The three enum modes keep their single-site `match` and the `Public` default, so a host that does
  nothing still gets public-by-hash reads. Adding another index-free mode is a new enum case and
  `match` arm the analyser forces to be handled.
- The decorator delegates writes to the policy the host supplies and gates reads through it, so the
  host wires the `Tenant`-mode base. A host whose read rule fits none of these implements
  `BlossomPolicyInterface` itself; the enum and the decorator are ready-made paths, not the only ones.
- A future reader must not fold `TenantOwned` back into the enum as a fourth case with an optional
  index: that reintroduces the nullable dependency, the construction throw, and the assertion this
  split removes. `TenantBlossomPolicyTest` pins the three enum modes; `TenantOwnedBlossomPolicyTest`
  pins the write delegation and the ownership check.
