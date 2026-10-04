<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\RetrievedBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Throwable;

final readonly class BlobArchive
{
    public function __construct(
        private BlobStoreInterface $store,
        private BlobIndexInterface $index,
    ) {
    }

    // Deliberate: bytes are stored before the index names them, and an index fault releases bytes no tenant indexes, so a reader never finds an entry without bytes and a failed write leaves no orphan — see ADR-0029
    public function store(PublicKey $tenant, string $tempPath, BlobDescriptor $descriptor): void
    {
        $hash = $descriptor->getSha256();
        $this->store->store($tempPath, $hash);

        try {
            $this->index->save($tenant, $descriptor);
        } catch (Throwable $indexFault) {
            $this->releaseUnreferenced($hash);

            throw $indexFault;
        }
    }

    public function discard(string $tempPath): void
    {
        $this->store->discard($tempPath);
    }

    public function retrieve(BlobHash $hash): RetrievedBlob|BlossomFailure
    {
        $descriptor = $this->index->findByHash($hash);
        if (null === $descriptor) {
            return BlobNotFoundFailure::forHash($hash->toHex());
        }

        $path = $this->store->retrievePath($hash);
        if (null === $path) {
            return BlobNotFoundFailure::forHash($hash->toHex());
        }

        return new RetrievedBlob($descriptor, $path);
    }

    // Deliberate: the bytes are deleted only when no tenant still indexes the hash — do not make the store delete unconditional — see ADR-0011
    public function removeForTenant(PublicKey $tenant, BlobHash $hash): bool
    {
        if (!$this->index->deleteForTenant($tenant, $hash)) {
            return false;
        }

        $this->releaseUnreferenced($hash);

        return true;
    }

    private function releaseUnreferenced(BlobHash $hash): void
    {
        if (null === $this->index->findByHash($hash)) {
            $this->store->delete($hash);
        }
    }
}
