<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Override;

final readonly class BlobIntegrityFailure extends BlossomFailure
{
    public static function declaredHashMismatch(string $declared, string $actual): self
    {
        return new self(sprintf('Declared hash %s does not match uploaded content hash %s', $declared, $actual));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::MalformedRequest;
    }
}
