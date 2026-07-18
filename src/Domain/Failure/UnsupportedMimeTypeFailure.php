<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Override;

final readonly class UnsupportedMimeTypeFailure extends BlossomFailure
{
    public static function forType(MimeType $mimeType): self
    {
        return new self(sprintf('MIME type not allowed: %s', $mimeType));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::UnsupportedMediaType;
    }
}
