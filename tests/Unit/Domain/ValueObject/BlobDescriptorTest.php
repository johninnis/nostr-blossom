<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Content\FileMetadata;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlobDescriptorTest extends TestCase
{
    public function testRejectsNegativeSize(): void
    {
        $sha256 = hash('sha256', 'blob');

        $this->expectException(InvalidArgumentException::class);

        new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256),
            sha256: BlossomFixtures::blobHash($sha256),
            size: -1,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );
    }

    public function testToArrayOmitsNip94WhenAbsent(): void
    {
        $sha256 = hash('sha256', 'blob');
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256.'.png'),
            sha256: BlossomFixtures::blobHash($sha256),
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        self::assertArrayNotHasKey('nip94', $descriptor->toArray());
    }

    public function testToArrayIncludesNip94TagsWhenPresent(): void
    {
        $sha256 = hash('sha256', 'blob');
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256.'.png'),
            sha256: BlossomFixtures::blobHash($sha256),
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
            nip94: new FileMetadata(
                url: 'https://blossom.test/'.$sha256.'.png',
                mimeType: 'image/png',
                hash: $sha256,
                size: 1024,
            ),
        );

        self::assertSame([
            ['url', 'https://blossom.test/'.$sha256.'.png'],
            ['m', 'image/png'],
            ['x', $sha256],
            ['size', '1024'],
        ], $descriptor->toArray()['nip94']);
    }
}
