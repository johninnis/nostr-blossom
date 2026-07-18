<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\StorableBlob;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MediaMetadata;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;

final class BlobDescriptorFactoryTest extends TestCase
{
    public function testBuildsDescriptorWithoutNip94WhenNoMediaOrOriginalHash(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'plain'));
        $storable = new StorableBlob(
            new IncomingBlob($hash, 2048),
            '/tmp/plain',
            BlossomFixtures::mimeType('image/png'),
        );

        $descriptor = $this->factory()->create($storable);

        self::assertSame('https://blossom.test/'.$hash->toHex().'.png', (string) $descriptor->getUrl());
        self::assertSame(2048, $descriptor->getSize());
        self::assertSame(1_000_000, $descriptor->getUploaded()->toInt());
        self::assertNull($descriptor->getNip94());
    }

    public function testBuildsNip94FromMediaMetadata(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'pixels'));
        $storable = new StorableBlob(
            new IncomingBlob($hash, 4096, new MediaMetadata('800x600', 'LEHV6nWB')),
            '/tmp/pixels',
            BlossomFixtures::mimeType('image/webp'),
        );

        $nip94 = $this->factory()->create($storable)->getNip94();

        self::assertNotNull($nip94);
        self::assertSame('800x600', $nip94->getDimensions());
        self::assertSame('LEHV6nWB', $nip94->getBlurhash());
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

        $nip94 = $this->factory()->create($storable)->getNip94();

        self::assertNotNull($nip94);
        self::assertSame($originalHash->toHex(), $nip94->getOriginalHash());
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
