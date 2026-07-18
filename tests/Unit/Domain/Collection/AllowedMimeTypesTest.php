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
        $allowed = new AllowedMimeTypes([BlossomFixtures::mimeType('image/png'), BlossomFixtures::mimeType('image/jpeg')]);

        self::assertTrue($allowed->contains(BlossomFixtures::mimeType('image/png')));
        self::assertTrue($allowed->contains(BlossomFixtures::mimeType('image/jpeg')));
        self::assertFalse($allowed->contains(BlossomFixtures::mimeType('video/mp4')));
    }

    public function testMembershipUsesNormalisedType(): void
    {
        $allowed = new AllowedMimeTypes([BlossomFixtures::mimeType('Image/PNG')]);

        self::assertTrue($allowed->contains(BlossomFixtures::mimeType('image/png')));
        self::assertTrue($allowed->contains(BlossomFixtures::mimeType('IMAGE/PNG')));
        self::assertSame(['image/png'], $this->stringValues($allowed));
    }

    public function testIsCountableAndIterable(): void
    {
        $allowed = new AllowedMimeTypes([BlossomFixtures::mimeType('image/png'), BlossomFixtures::mimeType('image/jpeg')]);

        self::assertCount(2, $allowed);
        self::assertContainsOnlyInstancesOf(MimeType::class, $allowed);
        self::assertSame(['image/png', 'image/jpeg'], $this->stringValues($allowed));
    }

    public function testAllowsEmptySet(): void
    {
        $allowed = new AllowedMimeTypes([]);

        self::assertTrue($allowed->isEmpty());
        self::assertSame([], $allowed->toArray());
        self::assertFalse($allowed->contains(BlossomFixtures::mimeType('image/png')));
    }

    public function testRejectsNonMimeTypeMember(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AllowedMimeTypes([BlossomFixtures::mimeType('image/png'), 'image/jpeg']);
    }

    /**
     * @return list<string>
     */
    private function stringValues(AllowedMimeTypes $allowed): array
    {
        return array_map(strval(...), $allowed->toArray());
    }
}
