<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Collection\BlobDescriptorCollection;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class ListBlobsUseCase
{
    public function __construct(
        private BlobIndexInterface $index,
        private BlossomAccessGate $gate,
    ) {
    }

    public function execute(string $authHeader, PublicKey $pubkey, ListQuery $query): BlobDescriptorCollection|BlossomFailure
    {
        return $this->gate->authoriseList($authHeader, $pubkey) ?? $this->index->list($pubkey, $query);
    }
}
