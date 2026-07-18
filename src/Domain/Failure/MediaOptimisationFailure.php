<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Override;

final readonly class MediaOptimisationFailure extends BlossomFailure
{
    public static function failed(MimeType $mimeType): self
    {
        return new self(sprintf('Media optimisation failed for type: %s', $mimeType));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::Internal;
    }
}
