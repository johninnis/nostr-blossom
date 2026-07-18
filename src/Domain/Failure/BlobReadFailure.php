<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Override;

final readonly class BlobReadFailure extends BlossomFailure
{
    public static function unreadable(): self
    {
        return new self('Unable to read blob data');
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::Internal;
    }
}
