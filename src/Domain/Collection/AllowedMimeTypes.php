<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Collection;

use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Core\Domain\Collection\KeyedCollection;
use Override;

// Deliberate: a KeyedCollection leaf (a list with index-backed membership), not a bespoke deduping set; empty is a valid deny-all — see ADR-0028, ADR-0005
/**
 * @extends KeyedCollection<MimeType>
 */
final class AllowedMimeTypes extends KeyedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return MimeType::class;
    }
}
