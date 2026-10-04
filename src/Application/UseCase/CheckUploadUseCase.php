<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\DeclaredBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;

final readonly class CheckUploadUseCase
{
    public function __construct(
        private BlossomAccessGate $gate,
        private UploadConstraints $constraints,
    ) {
    }

    public function execute(string $authHeader, DeclaredBlob $declared): ?BlossomFailure
    {
        return $this->gate->authoriseUploadCheck(
            $authHeader,
            $declared->getHash(),
            fn (): ?BlossomFailure => $this->constraints->guardUploadType($declared->getMimeType())
                ?? $this->constraints->guardUploadSize($declared->getSize()),
        );
    }
}
