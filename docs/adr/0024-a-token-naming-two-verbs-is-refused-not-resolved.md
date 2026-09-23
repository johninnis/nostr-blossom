# 0024. A token naming two verbs is refused, not resolved

## Status

Accepted

## Context

BUD-11 binds a kind-24242 authorisation event to a single operation through a `t` tag — `upload`, `media`, `delete`, `list`, or `get`. The tag is what stops a token minted to fetch one blob from being replayed to delete another. As with the expiration tag, nothing in the spec forbids stating it twice, and nothing says which one wins.

Taking the first `t` tag makes the answer positional, with the same consequence as the expiration tag had: the tag order is inside the signed payload, so a signer choosing to name two verbs decides the outcome by where the tags sit rather than by what the token says. Three readings are available:

- **First wins.** Order decides which of two verbs a token authorises.
- **Any match wins.** A token naming `upload` and `delete` authorises both. This turns the tag from a binding into a menu, and one signed token would carry the authority of two — exactly the narrowing the tag exists to provide.
- **Refuse.** A token that names more than one operation has not named one, so it authorises nothing.

The rest of the authorisation-event family already answers this the third way. The kind-27235 request validator rejects an event carrying duplicate `u`, `method`, or `payload` tags, and the NIP-42 validator rejects duplicate `challenge` or `relay` tags, rather than picking among them. A verb tag is the same kind of thing: a binding, where two values are a contradiction rather than a choice.

## Decision

The verb check reads the token's `t` tags through the hashtag accessor, which returns them deduplicated. A token naming more than one **distinct** verb is refused with `AuthenticationFailure::ambiguousVerb` — whatever verb was requested, and whatever order the tags are in. Exactly one distinct verb is compared against the requested verb as before; naming none keeps the existing wrong-verb refusal with an empty actual.

Repeating the *same* verb is not ambiguity, and is accepted.

## Consequences

- No token ever authorises two operations, and no token's meaning depends on where its tags sit. A signer that wants to upload and delete mints two tokens, each naming what it does.
- Verb comparison becomes case-insensitive. The hashtag reading lowercases each `t` value before comparing and deduplicating, so a token tagged `UPLOAD` now satisfies the `upload` verb where it previously failed, and `upload` alongside `UPLOAD` counts as one verb rather than two. That follows from treating `t` as the hashtag it is; the alternative is a second, local answer to what a `t` value means, kept in step by hand.
- The refusal is an authentication failure raised while parsing the envelope, so it still precedes the signature check and costs the server no curve operation (ADR-0019).
- The failure names every verb the token stated, so a client with a tagging bug is told what it sent rather than only that it was wrong.
- A future reader must not "helpfully" admit a token whose verb list happens to contain the requested verb, nor restore first-tag-wins for leniency. Pinned by `BlossomAuthValidatorTest`'s `testRejectsTokenNamingMoreThanOneVerb`, `testVerbRefusalIsIndependentOfHashtagTagOrder`, and `testAcceptsTokenRepeatingOneVerb`.
