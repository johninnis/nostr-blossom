# 7. The HTTP status code lives on the domain failure category

## Status

Accepted

## Context

`BlossomFailureCategory::httpStatus()` maps each failure category to an HTTP status code (`415`, `413`, `404`, `502`, …). An HTTP status on a domain enum reads like a layer violation: the Domain is supposed to be free of transport concerns, and a host could in principle choose its own status mapping.

## Decision

The canonical category-to-status mapping lives on `BlossomFailureCategory::httpStatus()`, in the Domain.

## Consequences

- Blossom is an HTTP-native protocol: the BUDs pin each failure mode to a specific status code, so the code is part of the *protocol contract*, not a transport detail a host is free to choose. Keeping the one canonical mapping in the Domain means every host answers identically and none re-derives the table — a single source of truth worth more here than keeping the integer out of the Domain.
- A host that needs a different surface (e.g. a JSON error envelope) still switches on the `category()` enum itself, not the status — the enum, not the integer, is the extension point.
- A future reader must not move this mapping into a host/Presentation layer "for cleanliness": that would let two hosts disagree on a status the BUD fixes. `BlossomFailureCategoryTest` pins the mapping.
