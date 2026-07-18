<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\DeclaredBlob;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DeclaredBlobTest extends TestCase
{
    public function testRejectsNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DeclaredBlob(BlossomFixtures::blobHash(hash('sha256', 'a')), -1, BlossomFixtures::mimeType('image/png'));
    }

    public function testExposesTheDeclaredTriple(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'a'));
        $declared = new DeclaredBlob($hash, 512, BlossomFixtures::mimeType('image/png'));

        self::assertTrue($declared->getHash()->equals($hash));
        self::assertSame(512, $declared->getSize());
        self::assertSame('image/png', (string) $declared->getMimeType());
    }
}
