<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

final readonly class MediaMetadata
{
    public function __construct(
        private ?string $dimensions = null,
        private ?string $blurhash = null,
    ) {
    }

    public function getDimensions(): ?string
    {
        return $this->dimensions;
    }

    public function getBlurhash(): ?string
    {
        return $this->blurhash;
    }

    public function isEmpty(): bool
    {
        return null === $this->dimensions && null === $this->blurhash;
    }
}
