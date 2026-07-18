<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use InvalidArgumentException;

final readonly class IncomingBlob
{
    public function __construct(
        private BlobHash $hash,
        private int $size,
        private ?MediaMetadata $media = null,
        private ?MimeType $detectedMimeType = null,
    ) {
        // Deliberate: a byte size stays a plain int; this non-negative guard is duplicated across the size-bearing value objects by design, not shared through a byte-size value object — see ADR-0022
        if ($size < 0) {
            throw new InvalidArgumentException('size cannot be negative');
        }
    }

    public function getHash(): BlobHash
    {
        return $this->hash;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMedia(): ?MediaMetadata
    {
        return $this->media;
    }

    public function resolvedMimeType(MimeType $declaredFallback): MimeType
    {
        if (null === $this->detectedMimeType || $this->detectedMimeType->isGeneric()) {
            return $declaredFallback;
        }

        return $this->detectedMimeType;
    }
}
