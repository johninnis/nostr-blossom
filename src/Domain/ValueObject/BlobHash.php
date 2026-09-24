<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use Override;
use Stringable;

final readonly class BlobHash implements Stringable
{
    private function __construct(private string $hex)
    {
    }

    public static function tryFromHex(string $hex): ?self
    {
        if (1 !== preg_match('/\A[0-9a-f]{64}\z/', $hex)) {
            return null;
        }

        return new self($hex);
    }

    public function toHex(): string
    {
        return $this->hex;
    }

    public function equals(self $other): bool
    {
        return $this->hex === $other->hex;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->hex;
    }
}
