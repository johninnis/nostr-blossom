<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\DTO;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;

final readonly class StorableBlob
{
    public function __construct(
        private IncomingBlob $blob,
        private string $path,
        private MimeType $mimeType,
        private ?BlobHash $originalHash = null,
    ) {
    }

    public function getBlob(): IncomingBlob
    {
        return $this->blob;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): MimeType
    {
        return $this->mimeType;
    }

    public function getOriginalHash(): ?BlobHash
    {
        return $this->originalHash;
    }

    public function getHash(): BlobHash
    {
        return $this->blob->getHash();
    }
}
