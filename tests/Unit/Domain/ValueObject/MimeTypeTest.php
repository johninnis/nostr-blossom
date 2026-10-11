<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MimeTypeTest extends TestCase
{
    public function testNormalisesToLowerCaseAndTrimsWhitespace(): void
    {
        self::assertSame('image/png', (string) BlossomFixtures::mimeType('  Image/PNG '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyOrWhitespaceOnlyValues(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
    }

    #[DataProvider('emptyOrWhitespaceOnlyValues')]
    public function testTreatsEmptyOrWhitespaceOnlyValueAsGeneric(string $value): void
    {
        self::assertTrue(MimeType::fromString($value)->isGeneric());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function structurallyInvalidValues(): iterable
    {
        yield 'no slash' => ['not-a-mime-type'];
        yield 'no subtype' => ['image/'];
        yield 'no type' => ['/png'];
    }

    #[DataProvider('structurallyInvalidValues')]
    public function testTreatsStructurallyInvalidValueAsGeneric(string $value): void
    {
        self::assertTrue(MimeType::fromString($value)->isGeneric());
    }

    public function testStripsParametersToTheBareMediaType(): void
    {
        self::assertSame('text/html', (string) MimeType::fromString('text/html; charset=utf-8'));
    }

    public function testKeepsAStructuredSyntaxSuffix(): void
    {
        self::assertSame('image/svg+xml', (string) MimeType::fromString('image/svg+xml'));
    }

    public function testStrictParseAcceptsAndCanonicalisesAValidEssence(): void
    {
        self::assertSame('image/png', (string) MimeType::tryFromString('  Image/PNG '));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesTheStrictParseRejects(): iterable
    {
        yield 'no slash' => ['imagepng'];
        yield 'no subtype' => ['image/'];
        yield 'empty' => [''];
    }

    #[DataProvider('valuesTheStrictParseRejects')]
    public function testStrictParseRejectsAStructurallyInvalidValue(string $value): void
    {
        self::assertNull(MimeType::tryFromString($value));
    }

    public function testStrictParseRejectsParameters(): void
    {
        self::assertNull(MimeType::tryFromString('text/html; charset=utf-8'));
    }

    public function testRecognisesTheGenericType(): void
    {
        self::assertTrue(MimeType::generic()->isGeneric());
    }

    public function testRecognisesOctetStreamAsTheGenericType(): void
    {
        self::assertTrue(BlossomFixtures::mimeType('application/octet-stream')->isGeneric());
    }

    public function testDoesNotTreatASpecificTypeAsGeneric(): void
    {
        self::assertFalse(BlossomFixtures::mimeType('image/png')->isGeneric());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function knownTypeExtensions(): iterable
    {
        yield 'jpeg' => ['image/jpeg', '.jpg'];
        yield 'webp' => ['image/webp', '.webp'];
    }

    #[DataProvider('knownTypeExtensions')]
    public function testExtensionForKnownType(string $type, string $extension): void
    {
        self::assertSame($extension, BlossomFixtures::mimeType($type)->getExtension());
    }

    public function testExtensionLookupIsCaseInsensitive(): void
    {
        self::assertSame('.png', BlossomFixtures::mimeType('IMAGE/PNG')->getExtension());
    }

    public function testExtensionIsEmptyForAnUnknownType(): void
    {
        self::assertSame('', BlossomFixtures::mimeType('text/plain')->getExtension());
    }

    public function testExtensionIsEmptyForTheGenericType(): void
    {
        self::assertSame('', MimeType::generic()->getExtension());
    }

    public function testEqualityIgnoresCase(): void
    {
        self::assertTrue(BlossomFixtures::mimeType('image/png')->equals(BlossomFixtures::mimeType('IMAGE/PNG')));
    }

    public function testEqualityDistinguishesAnotherType(): void
    {
        self::assertFalse(BlossomFixtures::mimeType('image/png')->equals(BlossomFixtures::mimeType('image/jpeg')));
    }
}
