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

    public function testExposesTheDeclaredHash(): void
    {
        self::assertTrue($this->declared()->getHash()->equals(BlossomFixtures::blobHash(hash('sha256', 'a'))));
    }

    public function testExposesTheDeclaredSize(): void
    {
        self::assertSame(512, $this->declared()->getSize());
    }

    public function testExposesTheDeclaredMimeType(): void
    {
        self::assertSame('image/png', (string) $this->declared()->getMimeType());
    }

    private function declared(): DeclaredBlob
    {
        return new DeclaredBlob(BlossomFixtures::blobHash(hash('sha256', 'a')), 512, BlossomFixtures::mimeType('image/png'));
    }
}
