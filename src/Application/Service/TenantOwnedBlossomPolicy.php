<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class TenantOwnedBlossomPolicy implements BlossomPolicyInterface
{
    public function __construct(
        private BlossomPolicyInterface $tenantPolicy,
        private BlobIndexInterface $index,
    ) {
    }

    #[Override]
    public function allowUpload(PublicKey $actor): ?BlossomFailure
    {
        return $this->tenantPolicy->allowUpload($actor);
    }

    #[Override]
    public function allowMedia(PublicKey $actor): ?BlossomFailure
    {
        return $this->tenantPolicy->allowMedia($actor);
    }

    #[Override]
    public function allowList(PublicKey $actor, PublicKey $target): ?BlossomFailure
    {
        return $this->tenantPolicy->allowList($actor, $target);
    }

    #[Override]
    public function allowDelete(PublicKey $actor): ?BlossomFailure
    {
        return $this->tenantPolicy->allowDelete($actor);
    }

    #[Override]
    public function allowGet(?PublicKey $actor, BlobHash $hash): ?BlossomFailure
    {
        if (null === $actor) {
            return AuthenticationFailure::missingHeader();
        }

        return $this->tenantPolicy->allowGet($actor, $hash)
            ?? ($this->index->ownsBlob($actor, $hash) ? null : AuthorisationFailure::mayOnlyAccessOwnBlobs());
    }
}
