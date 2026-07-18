<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Collection;

use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

// Deliberate: a TypedCollection leaf (a list with index-backed membership), not a bespoke deduping set; empty is a valid deny-all — see ADR-0013, ADR-0005
/**
 * @extends TypedCollection<MimeType>
 */
final class AllowedMimeTypes extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return MimeType::class;
    }

    public function contains(MimeType $mimeType): bool
    {
        return $this->containsByKey((string) $mimeType, static fn (MimeType $type): string => (string) $type);
    }
}
