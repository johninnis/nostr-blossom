# 0022. A blob's byte size stays a primitive `int`, not a value object

## Status

Accepted

## Context

Three value objects carry a blob's byte size as an `int` and each guards it with the same check:

```php
if ($size < 0) {
    throw new InvalidArgumentException('size cannot be negative');
}
```

`IncomingBlob` (from the inspector), `DeclaredBlob` (from the client's declared headers), and `BlobDescriptor` (the stored wire descriptor) all repeat it, and `UploadConstraints` carries the maximum upload size as an `int` as well.

That repetition, and a bare `int` travelling through every layer, read like a missing value object: a `ByteSize` that would own the non-negative invariant once and be threaded through, the way every other boundary quantity in this package (`BlobHash`, `MimeType`, `HttpUrl`, `Timestamp`) is a type rather than a primitive. A reviewer will be strongly tempted to introduce one and delete the duplicated guard.

## Decision

The byte size stays a plain `int`. The non-negative guard is duplicated across the three size-bearing value objects, and the maximum-upload limit stays an `int` on `UploadConstraints`. That duplication is accepted, not removed by a shared byte-size type.

A byte size in this domain is a **boundary quantity, not a value the domain operates on**. It is born from a filesystem `stat` or an HTTP `Content-Length` header; it is written straight into the stored descriptor's JSON and a persistence row; it is emitted back as a decimal `Content-Length` string and formatted for display. In the entire package it takes part in exactly **one** comparison — the uploaded size against the configured maximum, in `UploadConstraints::guardUploadSize`.

A value object would therefore be constructed at every input edge and unwrapped at every output edge, doing nothing in between. This was not judged in the abstract: the type was implemented and threaded through, then measured against a real host. It forced a wrap at each construction site and an unwrap at each read — the persistence bind, the `Content-Length` header, the body-size arithmetic, the byte formatter — with no benefit at any of them, to serve one internal comparison. The type's reach far exceeded the cost it removed, so it was withdrawn.

## Consequences

- The accepted cost is a two-line non-negative guard repeated in `IncomingBlob`, `DeclaredBlob`, and `BlobDescriptor`. Each is pinned by that object's own rejects-negative-size test, so the invariant cannot silently lapse in any of them.
- The maximum-upload limit stays an `int`: it is a configuration threshold, a scalar, and wrapping it bought only unwraps at the points a host advertises or compares it.
- A future reader must not "correct" the duplication by introducing a byte-size value object and threading it through the layers. The duplication is cheaper than the type for a quantity that only ever enters at one edge and leaves at another; the type was tried and reverted on exactly these grounds. The three guards carry a fence comment pointing here.
