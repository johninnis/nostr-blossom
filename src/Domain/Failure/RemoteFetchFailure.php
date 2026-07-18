<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Override;

final readonly class RemoteFetchFailure extends BlossomFailure
{
    public static function forUrl(HttpUrl $url): self
    {
        return new self(sprintf('Failed to fetch remote blob: %s', $url));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::UpstreamFailure;
    }
}
