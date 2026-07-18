<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Domain\Enum\GetAccessPolicy;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\TenantPubkeys;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class TenantBlossomPolicy implements BlossomPolicyInterface
{
    public function __construct(
        private TenantPubkeys $tenantPubkeys,
        private GetAccessPolicy $getAccess = GetAccessPolicy::Public,
    ) {
    }

    #[Override]
    public function allowUpload(PublicKey $actor): ?BlossomFailure
    {
        return $this->requireTenant($actor);
    }

    #[Override]
    public function allowMedia(PublicKey $actor): ?BlossomFailure
    {
        return $this->requireTenant($actor);
    }

    #[Override]
    public function allowList(PublicKey $actor, PublicKey $target): ?BlossomFailure
    {
        return $this->requireTenant($actor)
            ?? ($actor->equals($target) ? null : AuthorisationFailure::mayOnlyListOwnBlobs());
    }

    #[Override]
    public function allowDelete(PublicKey $actor): ?BlossomFailure
    {
        return $this->requireTenant($actor);
    }

    #[Override]
    public function allowGet(?PublicKey $actor, BlobHash $hash): ?BlossomFailure
    {
        return match ($this->getAccess) {
            GetAccessPolicy::Public => null,
            GetAccessPolicy::Authenticated => null === $actor ? AuthenticationFailure::missingHeader() : null,
            GetAccessPolicy::Tenant => $this->requireAuthenticatedTenant($actor),
        };
    }

    private function requireAuthenticatedTenant(?PublicKey $actor): ?BlossomFailure
    {
        if (null === $actor) {
            return AuthenticationFailure::missingHeader();
        }

        return $this->requireTenant($actor);
    }

    private function requireTenant(PublicKey $actor): ?BlossomFailure
    {
        return $this->tenantPubkeys->contains($actor) ? null : AuthorisationFailure::notTenant();
    }
}
