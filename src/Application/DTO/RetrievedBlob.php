<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\DTO;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;

final readonly class RetrievedBlob
{
    public function __construct(
        private BlobDescriptor $descriptor,
        private string $path,
    ) {
    }

    public function getDescriptor(): BlobDescriptor
    {
        return $this->descriptor;
    }

    public function getPath(): string
    {
        return $this->path;
    }
}
