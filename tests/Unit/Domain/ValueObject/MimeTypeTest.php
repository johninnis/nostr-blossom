<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use PHPUnit\Framework\TestCase;

final class MimeTypeTest extends TestCase
{
    public function testNormalisesToLowerCaseAndTrimsWhitespace(): void
    {
        self::assertSame('image/png', (string) BlossomFixtures::mimeType('  Image/PNG '));
    }

    public function testTreatsEmptyOrWhitespaceOnlyValueAsGeneric(): void
    {
        self::assertTrue(MimeType::fromString('')->isGeneric());
        self::assertTrue(MimeType::fromString('   ')->isGeneric());
    }

    public function testTreatsStructurallyInvalidValueAsGeneric(): void
    {
        self::assertTrue(MimeType::fromString('not-a-mime-type')->isGeneric());
        self::assertTrue(MimeType::fromString('image/')->isGeneric());
        self::assertTrue(MimeType::fromString('/png')->isGeneric());
    }

    public function testStripsParametersToTheBareMediaType(): void
    {
        self::assertSame('text/html', (string) MimeType::fromString('text/html; charset=utf-8'));
        self::assertSame('image/svg+xml', (string) MimeType::fromString('image/svg+xml'));
    }

    public function testStrictParseAcceptsAndCanonicalisesAValidEssence(): void
    {
        self::assertSame('image/png', (string) MimeType::tryFromString('  Image/PNG '));
    }

    public function testStrictParseRejectsAStructurallyInvalidValue(): void
    {
        self::assertNull(MimeType::tryFromString('imagepng'));
        self::assertNull(MimeType::tryFromString('image/'));
        self::assertNull(MimeType::tryFromString(''));
    }

    public function testStrictParseRejectsParameters(): void
    {
        self::assertNull(MimeType::tryFromString('text/html; charset=utf-8'));
    }

    public function testRecognisesTheGenericType(): void
    {
        self::assertTrue(MimeType::generic()->isGeneric());
        self::assertTrue(BlossomFixtures::mimeType('application/octet-stream')->isGeneric());
        self::assertFalse(BlossomFixtures::mimeType('image/png')->isGeneric());
    }

    public function testExtensionForKnownType(): void
    {
        self::assertSame('.jpg', BlossomFixtures::mimeType('image/jpeg')->getExtension());
        self::assertSame('.webp', BlossomFixtures::mimeType('image/webp')->getExtension());
    }

    public function testExtensionLookupIsCaseInsensitive(): void
    {
        self::assertSame('.png', BlossomFixtures::mimeType('IMAGE/PNG')->getExtension());
    }

    public function testExtensionIsEmptyForUnknownAndGenericTypes(): void
    {
        self::assertSame('', BlossomFixtures::mimeType('text/plain')->getExtension());
        self::assertSame('', MimeType::generic()->getExtension());
    }

    public function testEqualityIgnoresCase(): void
    {
        self::assertTrue(BlossomFixtures::mimeType('image/png')->equals(BlossomFixtures::mimeType('IMAGE/PNG')));
        self::assertFalse(BlossomFixtures::mimeType('image/png')->equals(BlossomFixtures::mimeType('image/jpeg')));
    }
}
