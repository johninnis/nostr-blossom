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
        self::assertTrue(new BlobDescriptorCollection()->isEmpty());
    }

    public function testAnEmptyCollectionCountsNothing(): void
    {
        self::assertCount(0, new BlobDescriptorCollection());
    }

    public function testAnEmptyCollectionSerialisesAsAnEmptyList(): void
    {
        self::assertSame([], new BlobDescriptorCollection()->jsonSerialize());
    }

    public function testACollectionHoldingADescriptorIsNotEmpty(): void
    {
        self::assertFalse(new BlobDescriptorCollection([$this->descriptor('blob')])->isEmpty());
    }

    public function testCountsItsDescriptors(): void
    {
        self::assertCount(1, new BlobDescriptorCollection([$this->descriptor('blob')]));
    }

    public function testIteratesItsDescriptors(): void
    {
        $descriptor = $this->descriptor('blob');

        self::assertSame([$descriptor], iterator_to_array(new BlobDescriptorCollection([$descriptor])));
    }

    public function testListsItsDescriptors(): void
    {
        $descriptor = $this->descriptor('blob');

        self::assertSame([$descriptor], new BlobDescriptorCollection([$descriptor])->toArray());
    }

    public function testSerialisesAsItsDescriptors(): void
    {
        $descriptor = $this->descriptor('blob');

        self::assertSame([$descriptor], new BlobDescriptorCollection([$descriptor])->jsonSerialize());
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
