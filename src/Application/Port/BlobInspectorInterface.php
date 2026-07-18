<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;

// Deliberate: two methods, not one with a flag — inspect() hashes the pre-optimise original (its hash anchors auth and ox) without the media decode only the stored output needs — see ADR-0012
interface BlobInspectorInterface
{
    public function inspect(string $tempPath): IncomingBlob;

    public function inspectWithMedia(string $tempPath): IncomingBlob;
}
