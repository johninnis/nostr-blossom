<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Override;

final readonly class BlobTooLargeFailure extends BlossomFailure
{
    public static function forSize(int $size, int $maxSize): self
    {
        return new self(sprintf('File size %d exceeds maximum upload size %d', $size, $maxSize));
    }

    public static function beyondMaximum(int $maxSize): self
    {
        return new self(sprintf('Upload exceeds maximum upload size %d', $maxSize));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::PayloadTooLarge;
    }
}
