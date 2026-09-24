# 11. Delete is reference-counted: bytes are removed only when no tenant still indexes the hash

## Status

Accepted

## Context

Blossom storage is content-addressed: a blob is the bytes whose SHA-256 is its identity. The store keeps one copy of those bytes per hash. The index, by contrast, is per-tenant: two tenants can each have an entry for the same hash, because both uploaded (or mirrored) the identical content and each sees it as theirs.

A delete is scoped to one tenant — `DeleteBlobUseCase` enforces that a caller may only delete its own blobs. The naive implementation deletes the index entry *and* the stored bytes. On a content-addressed store with sharing, that is a data-loss bug: tenant A deleting its copy would erase the bytes tenant B still indexes, and tenant B's blob would 404 even though B never deleted anything.

## Decision

`BlobRemover.removeForTenant` removes the tenant's index entry first (`BlobIndexInterface::deleteForTenant`). It then deletes the stored bytes (`BlobStoreInterface::delete`) **only if** no index entry for that hash remains (`BlobIndexInterface::findByHash` returns null). If another tenant still indexes the hash, the bytes stay. The method returns whether the tenant had an entry to delete at all, which the use case maps to 404 when false.

## Consequences

- Deleting one tenant's copy never affects another tenant's. The bytes survive exactly as long as some tenant indexes them, which is the correct lifetime for shared, content-addressed storage.
- The byte deletion is conditional on a fresh `findByHash` read after the index delete, so the reference count is read from the index of record rather than tracked separately — there is no counter to drift out of sync. The cost is one extra index read per delete.
- This is not a smell to "simplify" into an unconditional `store->delete`: that would reintroduce the cross-tenant data-loss bug. The conditional delete is pinned by the remover's tests.
- There is a benign race under concurrent uploads: a second tenant could index the hash between the `deleteForTenant` and the `findByHash`, or the bytes could be deleted just as another upload re-references them. Resolving that is the host's storage adapter's concern (its own locking or re-materialisation on the next upload), not this orchestration's; the content-addressed store can always reconstruct the bytes from a fresh upload of the same content.
