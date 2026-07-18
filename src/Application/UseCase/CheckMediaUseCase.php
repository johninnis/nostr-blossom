<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\DeclaredBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;

final readonly class CheckMediaUseCase
{
    public function __construct(
        private MediaOptimiserInterface $optimiser,
        private BlossomAuthValidatorInterface $authValidator,
        private BlossomPolicyInterface $policy,
        private UploadConstraints $constraints,
    ) {
    }

    public function execute(string $authHeader, DeclaredBlob $declared): ?BlossomFailure
    {
        $auth = $this->authValidator->parse(BlossomVerb::Media, $authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        // Deliberate: admission, the declared guards, and the x-tag binding read only the request and public config, so all run before the expensive verify() — see ADR-0003, ADR-0019
        return $this->policy->allowMedia($auth->getPubkey())
            ?? $this->guardOptimisableType($declared->getMimeType())
            ?? $this->constraints->guardUploadSize($declared->getSize())
            ?? $this->authValidator->requireAuthorisedBlob($auth, $declared->getHash())
            ?? $this->authValidator->verify($auth);
    }

    private function guardOptimisableType(MimeType $mimeType): ?BlossomFailure
    {
        return $this->optimiser->supports($mimeType) ? null : UnsupportedMimeTypeFailure::forType($mimeType);
    }
}
