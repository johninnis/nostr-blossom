<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Override;

final readonly class BlobNotFoundFailure extends BlossomFailure
{
    public static function forHash(string $sha256): self
    {
        return new self(sprintf('Blob not found: %s', $sha256));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::NotFound;
    }
}
