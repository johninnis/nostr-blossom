<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use PHPUnit\Framework\TestCase;

final class BlobHashTest extends TestCase
{
    public function testFromHexWithValidHash(): void
    {
        $hex = hash('sha256', 'test content');
        $hash = BlobHash::tryFromHex($hex);

        self::assertNotNull($hash);
        self::assertSame($hex, $hash->toHex());
    }

    public function testFromHexRejectsInvalidLength(): void
    {
        self::assertNull(BlobHash::tryFromHex('abc123'));
    }

    public function testFromHexRejectsUppercase(): void
    {
        self::assertNull(BlobHash::tryFromHex(strtoupper(hash('sha256', 'test'))));
    }

    public function testEquality(): void
    {
        $hex = hash('sha256', 'same');
        $a = BlobHash::tryFromHex($hex);
        $b = BlobHash::tryFromHex($hex);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertTrue($a->equals($b));
    }

    public function testInequality(): void
    {
        $a = BlobHash::tryFromHex(hash('sha256', 'one'));
        $b = BlobHash::tryFromHex(hash('sha256', 'two'));

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertFalse($a->equals($b));
    }

    public function testCastsToHexString(): void
    {
        $hex = hash('sha256', 'stringable');
        $hash = BlobHash::tryFromHex($hex);

        self::assertNotNull($hash);
        self::assertSame($hex, (string) $hash);
    }
}
