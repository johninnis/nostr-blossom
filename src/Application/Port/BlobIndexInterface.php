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

    /**
     * The tenant's blobs as BUD-12's `/list/<pubkey>` serves them: newest first by uploaded date, at
     * most `ListQuery::getLimit()` entries, and when the query carries a cursor, the page after the
     * cursor's blob, never including it. A cursor that names no blob of the tenant's pages from the top.
     */
    public function list(PublicKey $tenant, ListQuery $query): BlobDescriptorCollection;

    public function deleteForTenant(PublicKey $tenant, BlobHash $hash): bool;
}
