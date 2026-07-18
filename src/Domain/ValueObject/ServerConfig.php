<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

// Deliberate: a composition root of three independent concerns, each collaborator depending on the one it needs — see ADR-0020
final readonly class ServerConfig
{
    public function __construct(
        private TenantPubkeys $tenantPubkeys,
        private UploadConstraints $uploadConstraints,
        private ServerIdentity $identity,
    ) {
    }

    public function getTenantPubkeys(): TenantPubkeys
    {
        return $this->tenantPubkeys;
    }

    public function getUploadConstraints(): UploadConstraints
    {
        return $this->uploadConstraints;
    }

    public function getIdentity(): ServerIdentity
    {
        return $this->identity;
    }
}
