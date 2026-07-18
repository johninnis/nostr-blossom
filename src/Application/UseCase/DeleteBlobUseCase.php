<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Service\BlobRemover;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

final readonly class DeleteBlobUseCase
{
    public function __construct(
        private BlobRemover $remover,
        private BlossomAuthValidatorInterface $authValidator,
        private BlossomPolicyInterface $policy,
    ) {
    }

    public function execute(string $authHeader, BlobHash $hash): ?BlossomFailure
    {
        $auth = $this->authValidator->parse(BlossomVerb::Delete, $authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        // Deliberate: admission and the x-tag binding read only the request and public config, so both run before the expensive verify() — see ADR-0003, ADR-0019
        $denied = $this->policy->allowDelete($auth->getPubkey())
            ?? $this->authValidator->requireAuthorisedBlob($auth, $hash)
            ?? $this->authValidator->verify($auth);
        if (null !== $denied) {
            return $denied;
        }

        return $this->remover->removeForTenant($auth->getPubkey(), $hash)
            ? null
            : BlobNotFoundFailure::forHash($hash->toHex());
    }
}
