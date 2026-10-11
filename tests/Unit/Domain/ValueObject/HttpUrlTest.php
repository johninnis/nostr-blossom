<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpUrlTest extends TestCase
{
    public function testAcceptsAbsoluteHttpsUrl(): void
    {
        self::assertSame('https://blossom.example.com', (string) HttpUrl::tryFromString('https://blossom.example.com'));
    }

    public function testAcceptsAbsoluteHttpUrl(): void
    {
        self::assertSame('http://localhost:8080', (string) HttpUrl::tryFromString('http://localhost:8080'));
    }

    public function testPreservesTrailingSlash(): void
    {
        self::assertSame('https://blossom.example.com/blobs/', (string) HttpUrl::tryFromString('https://blossom.example.com/blobs/'));
    }

    public function testPreservesTrailingSlashInQueryAndFragment(): void
    {
        self::assertSame('https://blossom.example.com/cb?next=/foo/#top/', (string) HttpUrl::tryFromString('https://blossom.example.com/cb?next=/foo/#top/'));
    }

    public function testRejectsUrlWithoutScheme(): void
    {
        self::assertNull(HttpUrl::tryFromString('blossom.example.com'));
    }

    public function testRejectsNonHttpScheme(): void
    {
        self::assertNull(HttpUrl::tryFromString('ftp://blossom.example.com'));
    }

    public function testRejectsUrlWithoutHost(): void
    {
        self::assertNull(HttpUrl::tryFromString('https://'));
    }

    public function testRejectsEmptyString(): void
    {
        self::assertNull(HttpUrl::tryFromString(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlsCarryingWhitespace(): iterable
    {
        yield 'a space in the path' => ['https://blossom.example.com/a b'];
        yield 'a leading space' => [' https://blossom.example.com'];
        yield 'a trailing newline' => ["https://blossom.example.com/x\n"];
    }

    #[DataProvider('urlsCarryingWhitespace')]
    public function testRejectsWhitespaceInUrl(string $url): void
    {
        self::assertNull(HttpUrl::tryFromString($url));
    }

    public function testRejectsControlCharactersInUrl(): void
    {
        self::assertNull(HttpUrl::tryFromString("https://blossom.example.com/\x01"));
    }

    public function testLowercasesSchemeAndHost(): void
    {
        self::assertSame('https://blossom.example.com/Path', (string) HttpUrl::tryFromString('HTTPS://Blossom.Example.COM/Path'));
    }

    public function testPreservesPort(): void
    {
        self::assertSame('http://localhost:8080/base', (string) HttpUrl::tryFromString('http://localhost:8080/base'));
    }

    public function testAppendCollapsesTheBaseTrailingSlash(): void
    {
        self::assertSame('https://blossom.example.com/abc.png', (string) BlossomFixtures::httpUrl('https://blossom.example.com/')->append('abc.png'));
    }

    public function testAppendCollapsesAPathTrailingSlash(): void
    {
        self::assertSame('https://blossom.example.com/blobs/abc.png', (string) BlossomFixtures::httpUrl('https://blossom.example.com/blobs/')->append('abc.png'));
    }

    public function testAppendIsAgnosticToLeadingSlash(): void
    {
        self::assertSame('https://blossom.example.com/abc.png', (string) BlossomFixtures::httpUrl('https://blossom.example.com')->append('/abc.png'));
    }

    public function testAppendInsertsSegmentBeforeQuery(): void
    {
        self::assertSame('https://blossom.example.com/blobs/abc.png?v=1', (string) BlossomFixtures::httpUrl('https://blossom.example.com/blobs?v=1')->append('abc.png'));
    }

    public function testAppendInsertsSegmentBeforeFragment(): void
    {
        self::assertSame('https://blossom.example.com/blobs/abc.png#section', (string) BlossomFixtures::httpUrl('https://blossom.example.com/blobs#section')->append('abc.png'));
    }

    public function testAppendReturnsValidatedInstance(): void
    {
        self::assertTrue(BlossomFixtures::httpUrl('https://blossom.example.com')->append('abc.png')->equals(BlossomFixtures::httpUrl('https://blossom.example.com/abc.png')));
    }

    public function testExposesTheNormalisedScheme(): void
    {
        self::assertSame('https', BlossomFixtures::httpUrl('HTTPS://Blossom.Example.COM:8443/Path')->getScheme());
    }

    public function testExposesTheNormalisedHost(): void
    {
        self::assertSame('blossom.example.com', BlossomFixtures::httpUrl('HTTPS://Blossom.Example.COM:8443/Path')->getHost());
    }

    public function testExposesThePort(): void
    {
        self::assertSame(8443, BlossomFixtures::httpUrl('HTTPS://Blossom.Example.COM:8443/Path')->getPort());
    }

    public function testPortIsNullWhenAbsent(): void
    {
        self::assertNull(BlossomFixtures::httpUrl('https://blossom.example.com/path')->getPort());
    }

    public function testEqualsComparesNormalisedValue(): void
    {
        self::assertTrue(BlossomFixtures::httpUrl('HTTPS://Blossom.Example.COM/path')->equals(BlossomFixtures::httpUrl('https://blossom.example.com/path')));
    }

    public function testEqualsDistinguishesAnotherHost(): void
    {
        self::assertFalse(BlossomFixtures::httpUrl('HTTPS://Blossom.Example.COM/path')->equals(BlossomFixtures::httpUrl('https://other.example.com/path')));
    }

    public function testEqualsDistinguishesTrailingSlashVariants(): void
    {
        self::assertFalse(BlossomFixtures::httpUrl('https://blossom.example.com/blobs/')->equals(BlossomFixtures::httpUrl('https://blossom.example.com/blobs')));
    }
}
