<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\DTO;

use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;

final readonly class PendingBlob
{
    public function __construct(
        private string $path,
        private MimeType $mimeType,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): MimeType
    {
        return $this->mimeType;
    }
}
