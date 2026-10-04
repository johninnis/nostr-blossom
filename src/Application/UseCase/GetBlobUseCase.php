<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\RetrievedBlob;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

final readonly class GetBlobUseCase
{
    public function __construct(
        private BlobArchive $archive,
        private BlossomAccessGate $gate,
    ) {
    }

    public function execute(BlobHash $hash, string $authHeader = ''): RetrievedBlob|BlossomFailure
    {
        return $this->gate->authoriseGet($authHeader, $hash) ?? $this->archive->retrieve($hash);
    }
}
