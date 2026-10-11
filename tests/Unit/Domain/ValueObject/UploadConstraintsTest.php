<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UploadConstraintsTest extends TestCase
{
    public function testAllowedMimeTypesAreEnumerableForAdvertising(): void
    {
        $constraints = BlossomFixtures::uploadConstraints(1024, ['image/png', 'image/jpeg']);

        self::assertSame(['image/png', 'image/jpeg'], array_map(strval(...), $constraints->getAllowedMimeTypes()->toArray()));
    }

    public function testAllowedMimeTypesAreMimeTypeObjects(): void
    {
        self::assertContainsOnlyInstancesOf(MimeType::class, BlossomFixtures::uploadConstraints(1024, ['Image/PNG'])->getAllowedMimeTypes());
    }

    public function testAllowedMimeTypesAreNormalised(): void
    {
        self::assertSame(['image/png'], array_map(strval(...), BlossomFixtures::uploadConstraints(1024, ['Image/PNG'])->getAllowedMimeTypes()->toArray()));
    }

    public function testExposesMaxUploadBytesForAdvertising(): void
    {
        self::assertSame(4096, BlossomFixtures::uploadConstraints(4096, ['image/png'])->getMaxUploadBytes());
    }

    public function testIsMimeTypeAllowed(): void
    {
        self::assertTrue($this->constraints()->isMimeTypeAllowed(BlossomFixtures::mimeType('image/png')));
    }

    public function testEachListedMimeTypeIsAllowed(): void
    {
        self::assertTrue($this->constraints()->isMimeTypeAllowed(BlossomFixtures::mimeType('image/jpeg')));
    }

    public function testAnUnlistedMimeTypeIsNotAllowed(): void
    {
        self::assertFalse($this->constraints()->isMimeTypeAllowed(BlossomFixtures::mimeType('video/mp4')));
    }

    public function testGuardUploadSizeAdmitsAWithinLimitSize(): void
    {
        self::assertNull($this->constraints()->guardUploadSize(1024));
    }

    public function testGuardUploadSizeRejectsAnOversizeUpload(): void
    {
        self::assertInstanceOf(BlobTooLargeFailure::class, $this->constraints()->guardUploadSize(1025));
    }

    public function testGuardUploadTypeAdmitsAnAllowedType(): void
    {
        self::assertNull($this->constraints()->guardUploadType(BlossomFixtures::mimeType('image/png')));
    }

    public function testGuardUploadTypeRejectsADisallowedType(): void
    {
        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $this->constraints()->guardUploadType(BlossomFixtures::mimeType('video/mp4')));
    }

    public function testRejectsNonPositiveMaxUploadBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlossomFixtures::uploadConstraints(0, ['image/png']);
    }

    private function constraints(): UploadConstraints
    {
        return BlossomFixtures::uploadConstraints(1024, ['image/png', 'image/jpeg']);
    }
}
