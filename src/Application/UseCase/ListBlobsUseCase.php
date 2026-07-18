<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Collection\BlobDescriptorCollection;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class ListBlobsUseCase
{
    public function __construct(
        private BlobIndexInterface $index,
        private BlossomAuthValidatorInterface $authValidator,
        private BlossomPolicyInterface $policy,
    ) {
    }

    public function execute(string $authHeader, PublicKey $pubkey, ListQuery $query): BlobDescriptorCollection|BlossomFailure
    {
        $auth = $this->authValidator->parse(BlossomVerb::List, $authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        // Deliberate: policy admission runs before verify(), so an unadmitted caller is rejected without paying secp256k1 — see ADR-0003
        return $this->policy->allowList($auth->getPubkey(), $pubkey)
            ?? $this->authValidator->verify($auth)
            ?? $this->index->list($pubkey, $query);
    }
}
