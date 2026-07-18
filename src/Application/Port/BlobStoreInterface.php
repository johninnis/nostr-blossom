<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

interface BlobStoreInterface
{
    public function store(string $tempPath, BlobHash $hash): void;

    public function discard(string $tempPath): void;

    public function retrievePath(BlobHash $hash): ?string;

    public function delete(BlobHash $hash): bool;
}
