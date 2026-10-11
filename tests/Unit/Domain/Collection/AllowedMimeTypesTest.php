<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\Collection;

use Innis\Nostr\Blossom\Domain\Collection\AllowedMimeTypes;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AllowedMimeTypesTest extends TestCase
{
    public function testContainsListedType(): void
    {
        self::assertTrue($this->pngAndJpeg()->contains(BlossomFixtures::mimeType('image/png')));
    }

    public function testContainsEachListedType(): void
    {
        self::assertTrue($this->pngAndJpeg()->contains(BlossomFixtures::mimeType('image/jpeg')));
    }

    public function testDoesNotContainAnUnlistedType(): void
    {
        self::assertFalse($this->pngAndJpeg()->contains(BlossomFixtures::mimeType('video/mp4')));
    }

    public function testMembershipUsesNormalisedType(): void
    {
        self::assertTrue($this->mixedCasePng()->contains(BlossomFixtures::mimeType('image/png')));
    }

    public function testMembershipIgnoresTheQueriedTypesCase(): void
    {
        self::assertTrue($this->mixedCasePng()->contains(BlossomFixtures::mimeType('IMAGE/PNG')));
    }

    public function testHoldsTheNormalisedType(): void
    {
        self::assertSame(['image/png'], $this->stringValues($this->mixedCasePng()));
    }

    public function testIsCountable(): void
    {
        self::assertCount(2, $this->pngAndJpeg());
    }

    public function testIteratesMimeTypes(): void
    {
        self::assertContainsOnlyInstancesOf(MimeType::class, $this->pngAndJpeg());
    }

    public function testKeepsTheListedOrder(): void
    {
        self::assertSame(['image/png', 'image/jpeg'], $this->stringValues($this->pngAndJpeg()));
    }

    public function testAllowsEmptySet(): void
    {
        self::assertTrue(new AllowedMimeTypes([])->isEmpty());
    }

    public function testAnEmptySetHoldsNoType(): void
    {
        self::assertSame([], new AllowedMimeTypes([])->toArray());
    }

    public function testAnEmptySetContainsNoType(): void
    {
        self::assertFalse(new AllowedMimeTypes([])->contains(BlossomFixtures::mimeType('image/png')));
    }

    public function testRejectsNonMimeTypeMember(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AllowedMimeTypes([BlossomFixtures::mimeType('image/png'), 'image/jpeg']);
    }

    private function pngAndJpeg(): AllowedMimeTypes
    {
        return new AllowedMimeTypes([BlossomFixtures::mimeType('image/png'), BlossomFixtures::mimeType('image/jpeg')]);
    }

    private function mixedCasePng(): AllowedMimeTypes
    {
        return new AllowedMimeTypes([BlossomFixtures::mimeType('Image/PNG')]);
    }

    /**
     * @return list<string>
     */
    private function stringValues(AllowedMimeTypes $allowed): array
    {
        return array_map(strval(...), $allowed->toArray());
    }
}
