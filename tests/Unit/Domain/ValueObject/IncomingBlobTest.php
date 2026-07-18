<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IncomingBlobTest extends TestCase
{
    public function testRejectsNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'a')), -1);
    }

    public function testAcceptsZeroSize(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', '')), 0);

        self::assertSame(0, $blob->getSize());
    }

    public function testDetectedMimeTypeTakesPrecedence(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'a')), 3, null, BlossomFixtures::mimeType('image/png'));

        self::assertSame('image/png', (string) $blob->resolvedMimeType(BlossomFixtures::mimeType('application/json')));
    }

    public function testFallsBackToDeclaredWhenDetectionIsGeneric(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'a')), 3, null, MimeType::generic());

        self::assertSame('image/jpeg', (string) $blob->resolvedMimeType(BlossomFixtures::mimeType('image/jpeg')));
    }

    public function testFallsBackToDeclaredWhenDetectionIsAbsent(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'a')), 3, null, null);

        self::assertSame('image/gif', (string) $blob->resolvedMimeType(BlossomFixtures::mimeType('image/gif')));
    }
}
