<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\RemoteBlobFetcherInterface;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\RemoteFetchFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Throwable;

final readonly class MirrorBlobUseCase
{
    public function __construct(
        private RemoteBlobFetcherInterface $fetcher,
        private BlossomAccessGate $gate,
        private BlobIngestor $ingestor,
    ) {
    }

    public function execute(string $authHeader, HttpUrl $url): BlobDescriptor|BlossomFailure
    {
        $auth = $this->gate->authoriseUpload($authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        try {
            $fetched = $this->fetcher->fetch($url);
        } catch (Throwable) {
            return RemoteFetchFailure::forUrl($url);
        }

        return $this->ingestor->ingest($auth, $fetched);
    }
}
