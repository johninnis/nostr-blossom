<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\StorableBlob;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MediaMetadata;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\FileMetadata;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;

final class BlobDescriptorFactoryTest extends TestCase
{
    public function testAddressesTheDescriptorUnderTheBaseUrlByHashAndExtension(): void
    {
        self::assertSame('https://blossom.test/'.hash('sha256', 'plain').'.png', (string) $this->plainDescriptor()->getUrl());
    }

    public function testCarriesTheBlobsSize(): void
    {
        self::assertSame(2048, $this->plainDescriptor()->getSize());
    }

    public function testStampsTheUploadTimeFromTheClock(): void
    {
        self::assertSame(1_000_000, $this->plainDescriptor()->getUploaded()->toInt());
    }

    public function testBuildsDescriptorWithoutNip94WhenNoMediaOrOriginalHash(): void
    {
        self::assertNull($this->plainDescriptor()->getNip94());
    }

    public function testBuildsNip94DimensionsFromMediaMetadata(): void
    {
        self::assertSame('800x600', $this->mediaNip94()?->getDimensions());
    }

    public function testBuildsNip94BlurhashFromMediaMetadata(): void
    {
        self::assertSame('LEHV6nWB', $this->mediaNip94()?->getBlurhash());
    }

    public function testBuildsNip94WithOriginalHashOnTheOptimisePath(): void
    {
        $storedHash = BlossomFixtures::blobHash(hash('sha256', 'optimised'));
        $originalHash = BlossomFixtures::blobHash(hash('sha256', 'original'));
        $storable = new StorableBlob(
            new IncomingBlob($storedHash, 512),
            '/tmp/optimised',
            BlossomFixtures::mimeType('image/webp'),
            $originalHash,
        );

        self::assertSame($originalHash->toHex(), $this->factory()->create($storable)->getNip94()?->getOriginalHash());
    }

    private function plainDescriptor(): BlobDescriptor
    {
        return $this->factory()->create(new StorableBlob(
            new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'plain')), 2048),
            '/tmp/plain',
            BlossomFixtures::mimeType('image/png'),
        ));
    }

    private function mediaNip94(): ?FileMetadata
    {
        return $this->factory()->create(new StorableBlob(
            new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'pixels')), 4096, new MediaMetadata('800x600', 'LEHV6nWB')),
            '/tmp/pixels',
            BlossomFixtures::mimeType('image/webp'),
        ))->getNip94();
    }

    private function factory(): BlobDescriptorFactory
    {
        $config = BlossomFixtures::serverConfig(
            tenantPubkeys: [BlossomFixtures::publicKey(str_repeat('a', 64))],
            maxUploadBytes: 1024 * 1024,
            allowedMimeTypes: ['image/png', 'image/webp'],
            baseUrl: 'https://blossom.test',
        );

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt(1_000_000));

        return new BlobDescriptorFactory($config->getIdentity(), $clock);
    }
}
