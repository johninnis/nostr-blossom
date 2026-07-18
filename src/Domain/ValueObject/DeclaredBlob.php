<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use InvalidArgumentException;

final readonly class DeclaredBlob
{
    public function __construct(
        private BlobHash $hash,
        private int $size,
        private MimeType $mimeType,
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

    public function getMimeType(): MimeType
    {
        return $this->mimeType;
    }
}
