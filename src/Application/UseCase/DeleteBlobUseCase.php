<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

final readonly class DeleteBlobUseCase
{
    public function __construct(
        private BlobArchive $archive,
        private BlossomAccessGate $gate,
    ) {
    }

    public function execute(string $authHeader, BlobHash $hash): ?BlossomFailure
    {
        $auth = $this->gate->authoriseDelete($authHeader, $hash);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        return $this->archive->removeForTenant($auth->getPubkey(), $hash)
            ? null
            : BlobNotFoundFailure::forHash($hash->toHex());
    }
}
