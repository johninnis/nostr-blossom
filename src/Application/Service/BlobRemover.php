<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class BlobRemover
{
    public function __construct(
        private BlobStoreInterface $store,
        private BlobIndexInterface $index,
    ) {
    }

    public function removeForTenant(PublicKey $tenant, BlobHash $hash): bool
    {
        if (!$this->index->deleteForTenant($tenant, $hash)) {
            return false;
        }

        if (null === $this->index->findByHash($hash)) {
            $this->store->delete($hash);
        }

        return true;
    }
}
