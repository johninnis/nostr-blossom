<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;

interface RemoteBlobFetcherInterface
{
    public function fetch(HttpUrl $url): PendingBlob;
}
