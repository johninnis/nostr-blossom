<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\RetrievedBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

final readonly class GetBlobUseCase
{
    public function __construct(
        private BlobStoreInterface $store,
        private BlobIndexInterface $index,
        private BlossomAuthValidatorInterface $authValidator,
        private BlossomPolicyInterface $policy,
    ) {
    }

    public function execute(BlobHash $hash, string $authHeader = ''): RetrievedBlob|BlossomFailure
    {
        $gated = $this->policy->allowGet(null, $hash);
        if (null === $gated) {
            return $this->retrieve($hash);
        }

        if ('' === $authHeader) {
            return $gated;
        }

        $auth = $this->authValidator->parse(BlossomVerb::Get, $authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        // Deliberate: the cheap x-tag scope check runs before verify (ADR-0015), and verify runs before the policy check because that check may read private per-blob ownership (ADR-0014)
        return $this->authValidator->requireBlobInScope($auth, $hash)
            ?? $this->authValidator->verify($auth)
            ?? $this->policy->allowGet($auth->getPubkey(), $hash)
            ?? $this->retrieve($hash);
    }

    private function retrieve(BlobHash $hash): RetrievedBlob|BlossomFailure
    {
        $descriptor = $this->index->findByHash($hash);
        if (null === $descriptor) {
            return BlobNotFoundFailure::forHash($hash->toHex());
        }

        $path = $this->store->retrievePath($hash);
        if (null === $path) {
            return BlobNotFoundFailure::forHash($hash->toHex());
        }

        return new RetrievedBlob($descriptor, $path);
    }
}
