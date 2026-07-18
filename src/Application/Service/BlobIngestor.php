<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\DTO\StorableBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\MediaOptimisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Throwable;

final readonly class BlobIngestor
{
    public function __construct(
        private BlobValidator $validator,
        private BlobDescriptorFactory $descriptorFactory,
        private BlobStoreInterface $store,
        private BlobIndexInterface $index,
    ) {
    }

    public function ingest(
        Event $auth,
        PendingBlob $pending,
        ?BlobHash $declaredHash = null,
    ): BlobDescriptor|BlossomFailure {
        $storable = $this->validator->validateStorable($auth, $pending, $declaredHash);
        if ($storable instanceof BlossomFailure) {
            return $this->reject($pending->getPath(), $storable);
        }

        return $this->persist($auth->getPubkey(), $storable);
    }

    // Deliberate: the optimiser is a per-call argument, not a constructor dependency upload/mirror would ignore — see ADR-0004
    public function ingestOptimised(
        Event $auth,
        PendingBlob $pending,
        MediaOptimiserInterface $optimiser,
    ): BlobDescriptor|BlossomFailure {
        if (!$optimiser->supports($pending->getMimeType())) {
            return $this->reject($pending->getPath(), UnsupportedMimeTypeFailure::forType($pending->getMimeType()));
        }

        $originalHash = $this->validator->admitOriginal($auth, $pending);
        if ($originalHash instanceof BlossomFailure) {
            return $this->reject($pending->getPath(), $originalHash);
        }

        $produced = $this->optimise($optimiser, $pending);
        if ($produced instanceof BlossomFailure) {
            return $produced;
        }

        $storable = $this->validator->validateProduced($produced, $originalHash);
        if ($storable instanceof BlossomFailure) {
            return $this->reject($produced->getPath(), $storable);
        }

        return $this->persist($auth->getPubkey(), $storable);
    }

    // Deliberate: the coordinator owns the temp-file lifecycle; the live file changes when the optimiser emits a new one, so a later failure discards whichever file is current — see ADR-0004
    private function optimise(MediaOptimiserInterface $optimiser, PendingBlob $original): PendingBlob|BlossomFailure
    {
        try {
            $produced = $optimiser->optimise($original);
        } catch (Throwable) {
            return $this->reject($original->getPath(), MediaOptimisationFailure::failed($original->getMimeType()));
        }

        if ($produced->getPath() !== $original->getPath()) {
            $this->store->discard($original->getPath());
        }

        return $produced;
    }

    private function persist(PublicKey $tenant, StorableBlob $storable): BlobDescriptor
    {
        $this->store->store($storable->getPath(), $storable->getHash());

        $descriptor = $this->descriptorFactory->create($storable);
        $this->index->save($tenant, $descriptor);

        return $descriptor;
    }

    private function reject(string $path, BlossomFailure $error): BlossomFailure
    {
        $this->store->discard($path);

        return $error;
    }
}
