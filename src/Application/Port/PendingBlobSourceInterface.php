<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;

// Deliberate: staging answers with a failure value as well as a blob — a source that stops reading at the size cap has an anticipated outcome to report, not a fault — see ADR-0025
interface PendingBlobSourceInterface
{
    public function stage(): PendingBlob|BlossomFailure;
}
