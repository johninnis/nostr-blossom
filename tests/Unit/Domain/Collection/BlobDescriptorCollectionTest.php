<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\Collection;

use Innis\Nostr\Blossom\Domain\Collection\BlobDescriptorCollection;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlobDescriptorCollectionTest extends TestCase
{
    public function testEmptyCollection(): void
    {
        $collection = new BlobDescriptorCollection();

        self::assertTrue($collection->isEmpty());
        self::assertCount(0, $collection);
        self::assertSame([], $collection->jsonSerialize());
    }

    public function testIteratesAndSerialisesDescriptors(): void
    {
        $descriptor = $this->descriptor('blob');
        $collection = new BlobDescriptorCollection([$descriptor]);

        self::assertFalse($collection->isEmpty());
        self::assertCount(1, $collection);
        self::assertSame([$descriptor], iterator_to_array($collection));
        self::assertSame([$descriptor], $collection->toArray());
        self::assertSame($descriptor->toArray(), $collection->jsonSerialize()[0]->toArray());
    }

    public function testRejectsNonDescriptorMember(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BlobDescriptorCollection(['not-a-descriptor']);
    }

    private function descriptor(string $content): BlobDescriptor
    {
        $sha256 = hash('sha256', $content);

        return new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256.'.png'),
            sha256: BlossomFixtures::blobHash($sha256),
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );
    }
}
