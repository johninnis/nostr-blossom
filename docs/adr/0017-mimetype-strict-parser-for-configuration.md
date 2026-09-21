# 0017. `MimeType` carries a strict parser for configuration strings beside the lenient header parser

## Status

Accepted

## Context

ADR-0005 gives `MimeType::fromString` its lenient behaviour: an unrecognised HTTP `Content-Type` degrades to `application/octet-stream`, because the BUD/NIP-94 wire contract mandates that fallback and a value parser of the header must not throw. The same ADR's consequences flagged the footgun: fed a *configuration* string, the degrade silently turns a typo (`imagepng`) into the generic type, so a host's allow-list admits `application/octet-stream` it never intended. The documented mitigation was for every host to validate its config strings itself, rejecting anything that round-trips to the generic type — the same guard, hand-rolled per host.

When every caller at one boundary needs the same wrapper, the missing piece belongs on the callee. The two boundaries want two failure modes: the wire header degrades (spec-mandated); a configuration string is parse-or-reject, the same `tryFromX` shape every other untrusted parse in this package takes (`BlobHash::tryFromHex`, `HttpUrl::tryFromString`, `ListQuery::tryFromQueryString`).

## Decision

`MimeType` exposes both named constructors, chosen by trust boundary:

- `tryFromString(string): ?self` — the strict parser for configuration and other parse-or-reject input. It canonicalises case and surrounding whitespace, and returns `null` for anything that is not a bare, well-formed `type/subtype` essence (including a value carrying parameters).
- `fromString(string): self` — the lenient header parser, unchanged in behaviour: it strips any `;`-parameters and degrades an invalid essence to the generic type. It is defined *as* `tryFromString(...) ?? generic()`, so there is one essence-validation codepath.

A host builds its `AllowedMimeTypes` from `tryFromString`, failing loudly on `null`, instead of hand-rolling the round-trip check.

## Consequences

- The config-typo footgun is closed at its source; the README's host responsibility shrinks from "re-implement this guard" to "use the strict parser".
- Two constructors with different failure modes on one value object are deliberate, not drift: they are the two trust boundaries of ADR-0005, and the lenient one delegates to the strict one. A future reader must not "unify" them into a single behaviour in either direction — a throwing/`null` header parse breaks the spec's fallback; a degrading config parse reintroduces the footgun. `MimeTypeTest` pins both modes.
