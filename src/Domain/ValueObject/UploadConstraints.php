<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\Collection\AllowedMimeTypes;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use InvalidArgumentException;

final readonly class UploadConstraints
{
    public function __construct(
        private int $maxUploadBytes,
        private AllowedMimeTypes $allowedMimeTypes,
    ) {
        if ($maxUploadBytes < 1) {
            throw new InvalidArgumentException('maxUploadBytes must be a positive integer');
        }
    }

    public function getMaxUploadBytes(): int
    {
        return $this->maxUploadBytes;
    }

    public function getAllowedMimeTypes(): AllowedMimeTypes
    {
        return $this->allowedMimeTypes;
    }

    public function isMimeTypeAllowed(MimeType $mimeType): bool
    {
        return $this->allowedMimeTypes->contains($mimeType);
    }

    public function guardUploadSize(int $size): ?BlossomFailure
    {
        return $size > $this->maxUploadBytes ? BlobTooLargeFailure::forSize($size, $this->maxUploadBytes) : null;
    }

    public function guardUploadType(MimeType $mimeType): ?BlossomFailure
    {
        return $this->isMimeTypeAllowed($mimeType) ? null : UnsupportedMimeTypeFailure::forType($mimeType);
    }
}
