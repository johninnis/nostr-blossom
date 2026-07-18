<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;

interface MediaOptimiserInterface
{
    public function optimise(PendingBlob $source): PendingBlob;

    public function supports(MimeType $mimeType): bool;
}
