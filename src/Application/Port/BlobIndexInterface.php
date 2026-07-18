<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Domain\Collection\BlobDescriptorCollection;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

interface BlobIndexInterface
{
    public function save(PublicKey $tenant, BlobDescriptor $descriptor): void;

    public function findByHash(BlobHash $hash): ?BlobDescriptor;

    public function ownsBlob(PublicKey $tenant, BlobHash $hash): bool;

    public function list(PublicKey $tenant, ListQuery $query): BlobDescriptorCollection;

    public function deleteForTenant(PublicKey $tenant, BlobHash $hash): bool;
}
