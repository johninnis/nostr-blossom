<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use PHPUnit\Framework\TestCase;

final class BlobHashTest extends TestCase
{
    public function testFromHexWithValidHash(): void
    {
        $hex = hash('sha256', 'test content');

        self::assertSame($hex, BlobHash::tryFromHex($hex)?->toHex());
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

        self::assertTrue(BlossomFixtures::blobHash($hex)->equals(BlossomFixtures::blobHash($hex)));
    }

    public function testInequality(): void
    {
        self::assertFalse(BlossomFixtures::blobHash(hash('sha256', 'one'))->equals(BlossomFixtures::blobHash(hash('sha256', 'two'))));
    }

    public function testCastsToHexString(): void
    {
        $hex = hash('sha256', 'stringable');

        self::assertSame($hex, (string) BlossomFixtures::blobHash($hex));
    }
}
