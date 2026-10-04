<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\DeclaredBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;

final readonly class CheckMediaUseCase
{
    public function __construct(
        private MediaOptimiserInterface $optimiser,
        private BlossomAccessGate $gate,
        private UploadConstraints $constraints,
    ) {
    }

    public function execute(string $authHeader, DeclaredBlob $declared): ?BlossomFailure
    {
        return $this->gate->authoriseMediaCheck(
            $authHeader,
            $declared->getHash(),
            fn (): ?BlossomFailure => $this->guardOptimisableType($declared->getMimeType())
                ?? $this->constraints->guardUploadSize($declared->getSize()),
        );
    }

    private function guardOptimisableType(MimeType $mimeType): ?BlossomFailure
    {
        return $this->optimiser->supports($mimeType) ? null : UnsupportedMimeTypeFailure::forType($mimeType);
    }
}
