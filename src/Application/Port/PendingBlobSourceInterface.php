<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;

interface PendingBlobSourceInterface
{
    public function stage(): PendingBlob;
}
