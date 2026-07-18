<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use InvalidArgumentException;

final readonly class TenantPubkeys
{
    private PublicKeyCollection $pubkeys;

    /**
     * @param array<array-key, mixed> $pubkeys
     */
    public function __construct(array $pubkeys)
    {
        // Deliberate: a server with no tenants can authorise nothing, so empty is rejected (unlike AllowedMimeTypes) — see ADR-0005
        $collection = new PublicKeyCollection($pubkeys)->unique();
        if ($collection->isEmpty()) {
            throw new InvalidArgumentException('tenantPubkeys must contain at least one pubkey');
        }

        $this->pubkeys = $collection;
    }

    public function contains(PublicKey $pubkey): bool
    {
        return $this->pubkeys->contains($pubkey);
    }

    /**
     * @return list<PublicKey>
     */
    public function toArray(): array
    {
        return $this->pubkeys->toArray();
    }

    /**
     * @return list<string>
     */
    public function toHexes(): array
    {
        return $this->pubkeys->toHexes();
    }
}
