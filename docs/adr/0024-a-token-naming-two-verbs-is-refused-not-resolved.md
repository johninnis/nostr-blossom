# 24. A token naming two verbs is refused, not resolved

## Status

Accepted

## Context

BUD-11 binds a kind-24242 authorisation event to a single operation through a `t` tag: `upload`, `media`, `delete`, `list`, or `get`. The tag is what stops a token minted to fetch one blob from being replayed to delete another. Nothing in the spec forbids stating it twice, and nothing says which occurrence wins.

Taking the first `t` tag makes the answer positional: the signer decides the outcome by where the tags sit rather than by what the token says. Accepting any occurrence that matches turns the tag from a binding into a menu, so one signed token carries the authority of two. The verb is a binding of the same kind as a NIP-98 `u`, `method` or `payload` tag and a NIP-42 `challenge` or `relay` tag: two values are a contradiction, not a choice, and a token that names more than one operation has not named one.

A `t` tag is also a hashtag, so reading the verb through the hashtag accessor looks like the way to avoid a second, local answer to what a `t` value means, kept in step by hand. But the hashtag accessor answers a different question, which topics an event carries, and it drops what names no topic: an empty value. For a binding that is the wrong answer. A token carrying `["t", "upload"]` and `["t", ""]` states two values, and the reader, not the token, would decide that one of them did not count. The hashtag accessor is also a second reader that counts distinct values, beside the sole-value reader innis/nostr-core ships for every tag that must mean one thing: `TagCollection::getSoleValueByType` and the `SoleTagValue` it answers with, absent, one value, or disagreeing.

The verb's vocabulary is case-insensitive in practice. BUD-11 lists the verbs in lower case, and NIP-24 says a `t` value must be lower case, so `UPLOAD` is a malformed spelling of `upload`, not another verb. Reading it as the verb it spells binds the token to the same action, so it admits nothing a lower-case token would not, and refusing it would fail a careless client for no gain.

## Decision

`BlossomAuthValidator::parse()` reads the token's `t` tags as one claim:

- Every `t` tag that carries a value is read, in one call to innis/nostr-core's sole-value reader, `TagCollection::getSoleValueByType`, with `strtolower` as its canonicaliser: each value is lower-cased, the verb vocabulary's own canonicalisation, before the values are compared. Lower-casing gives every value a form, so none is left out.
- Values that disagree name no verb, and the token is refused with `AuthenticationFailure::ambiguousVerb`, naming the distinct lower-cased values the reader saw (`SoleTagValue::getDistinctValues`), whatever verb was requested and whatever the tag order. An empty value is a value: `["t", "upload"]` beside `["t", ""]` disagree, and the token is refused.
- One value is compared with the requested verb, and a token whose value differs is refused with `AuthenticationFailure::wrongVerb`. A lone empty value is such a difference. A token with no `t` value keeps the wrong-verb refusal with an empty actual.
- Repeating one verb is one claim and is accepted, in any case: `upload` beside `UPLOAD` is the verb `upload`.

## Consequences

- No token ever authorises two operations, and no token's meaning depends on where its tags sit. A signer that wants to upload and delete mints two tokens, each naming what it does.
- A token carrying an empty `t` value beside its verb is refused. A client that writes an empty `t` must stop writing it.
- The verb is case-insensitive by its own lower-casing, not by the hashtag reading. The lower-casing is ASCII, the alphabet the verbs are written in; a value spelt with any other letter was never a verb and is not one now.
- The refusal is an authentication failure raised while parsing the envelope. It reads only the token, so it still precedes signature verification and its answer is the same whichever key signed the token (ADR-0026).
- The failure names every distinct value the token stated, lower-cased and the empty one included, so a client with a tagging bug is told what it sent rather than only that it was wrong.
- A future reader must not restore the hashtag accessor for the verb, drop an empty value, "helpfully" admit a token whose values happen to include the requested verb, nor restore first-tag-wins for leniency. Pinned by `BlossomAuthValidatorTest`'s `testRejectsTokenNamingMoreThanOneVerb`, `testVerbRefusalIsIndependentOfHashtagTagOrder`, `testAcceptsTokenRepeatingOneVerb`, `testRejectsTokenWhoseVerbTagsDisagreeOnAnEmptyValue`, and `testAcceptsTokenNamingOneVerbInTwoCases`.
- An earlier version of this record read the verb through the hashtag accessor, which drops an empty value, so a token carrying `["t", "upload"]` beside `["t", ""]` named one verb and was accepted; it read `UPLOAD` as `upload` only as a side effect of that reading.
- Shared decision: nostr-adrs ADR-0014.
