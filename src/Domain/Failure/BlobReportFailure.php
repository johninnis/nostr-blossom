<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Override;

final readonly class BlobReportFailure extends BlossomFailure
{
    public static function malformedEvent(): self
    {
        return new self('Report body is not a valid Nostr event');
    }

    public static function invalidSignature(): self
    {
        return new self('Report event signature is invalid');
    }

    public static function unexpectedKind(): self
    {
        return new self(sprintf('Report event kind must be %d', EventKind::REPORTING));
    }

    public static function missingBlobReference(): self
    {
        return new self('Report event has no blob hash to report');
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::MalformedRequest;
    }
}
