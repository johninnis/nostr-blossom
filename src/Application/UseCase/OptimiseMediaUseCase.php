<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;

final readonly class OptimiseMediaUseCase
{
    public function __construct(
        private MediaOptimiserInterface $optimiser,
        private BlossomAuthValidatorInterface $authValidator,
        private BlossomPolicyInterface $policy,
        private BlobIngestor $ingestor,
    ) {
    }

    public function execute(string $authHeader, PendingBlobSourceInterface $source): BlobDescriptor|BlossomFailure
    {
        $auth = $this->authValidator->parse(BlossomVerb::Media, $authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        // Deliberate: policy admission runs before verify(), so an unadmitted caller is rejected without paying secp256k1 — see ADR-0003
        return $this->policy->allowMedia($auth->getPubkey())
            ?? $this->authValidator->verify($auth)
            ?? $this->ingestor->ingestOptimised($auth, $source->stage(), $this->optimiser);
    }
}
