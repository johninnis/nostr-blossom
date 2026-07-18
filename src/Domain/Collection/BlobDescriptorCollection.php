<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Collection;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use JsonSerializable;
use Override;

/**
 * @extends TypedCollection<BlobDescriptor>
 */
final class BlobDescriptorCollection extends TypedCollection implements JsonSerializable
{
    #[Override]
    protected function elementType(): string
    {
        return BlobDescriptor::class;
    }

    /**
     * @return list<BlobDescriptor>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
