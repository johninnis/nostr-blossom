<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

final readonly class UploadBlobUseCase
{
    public function __construct(
        private BlossomAccessGate $gate,
        private BlobIngestor $ingestor,
    ) {
    }

    public function execute(string $authHeader, PendingBlobSourceInterface $source, ?BlobHash $declaredHash): BlobDescriptor|BlossomFailure
    {
        $auth = $this->gate->authoriseUpload($authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        $pending = $source->stage();
        if ($pending instanceof BlossomFailure) {
            return $pending;
        }

        return $this->ingestor->ingest($auth, $pending, $declaredHash);
    }
}
