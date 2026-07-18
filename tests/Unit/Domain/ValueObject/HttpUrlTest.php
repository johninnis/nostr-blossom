<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use PHPUnit\Framework\TestCase;

final class HttpUrlTest extends TestCase
{
    public function testAcceptsAbsoluteHttpsUrl(): void
    {
        $url = HttpUrl::tryFromString('https://blossom.example.com');

        self::assertNotNull($url);
        self::assertSame('https://blossom.example.com', (string) $url);
    }

    public function testAcceptsAbsoluteHttpUrl(): void
    {
        $url = HttpUrl::tryFromString('http://localhost:8080');

        self::assertNotNull($url);
        self::assertSame('http://localhost:8080', (string) $url);
    }

    public function testPreservesTrailingSlash(): void
    {
        $url = HttpUrl::tryFromString('https://blossom.example.com/blobs/');

        self::assertNotNull($url);
        self::assertSame('https://blossom.example.com/blobs/', (string) $url);
    }

    public function testPreservesTrailingSlashInQueryAndFragment(): void
    {
        $url = HttpUrl::tryFromString('https://blossom.example.com/cb?next=/foo/#top/');

        self::assertNotNull($url);
        self::assertSame('https://blossom.example.com/cb?next=/foo/#top/', (string) $url);
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

    public function testRejectsWhitespaceInUrl(): void
    {
        self::assertNull(HttpUrl::tryFromString('https://blossom.example.com/a b'));
        self::assertNull(HttpUrl::tryFromString(' https://blossom.example.com'));
        self::assertNull(HttpUrl::tryFromString("https://blossom.example.com/x\n"));
    }

    public function testRejectsControlCharactersInUrl(): void
    {
        self::assertNull(HttpUrl::tryFromString("https://blossom.example.com/\x01"));
    }

    public function testLowercasesSchemeAndHost(): void
    {
        $url = HttpUrl::tryFromString('HTTPS://Blossom.Example.COM/Path');

        self::assertNotNull($url);
        self::assertSame('https://blossom.example.com/Path', (string) $url);
    }

    public function testPreservesPort(): void
    {
        $url = HttpUrl::tryFromString('http://localhost:8080/base');

        self::assertNotNull($url);
        self::assertSame('http://localhost:8080/base', (string) $url);
    }

    public function testAppendCollapsesTheBaseTrailingSlash(): void
    {
        $base = HttpUrl::tryFromString('https://blossom.example.com/');

        self::assertNotNull($base);
        self::assertSame('https://blossom.example.com/abc.png', (string) $base->append('abc.png'));
    }

    public function testAppendCollapsesAPathTrailingSlash(): void
    {
        $base = HttpUrl::tryFromString('https://blossom.example.com/blobs/');

        self::assertNotNull($base);
        self::assertSame('https://blossom.example.com/blobs/abc.png', (string) $base->append('abc.png'));
    }

    public function testAppendIsAgnosticToLeadingSlash(): void
    {
        $base = HttpUrl::tryFromString('https://blossom.example.com');

        self::assertNotNull($base);
        self::assertSame('https://blossom.example.com/abc.png', (string) $base->append('/abc.png'));
    }

    public function testAppendInsertsSegmentBeforeQuery(): void
    {
        $base = HttpUrl::tryFromString('https://blossom.example.com/blobs?v=1');

        self::assertNotNull($base);
        self::assertSame('https://blossom.example.com/blobs/abc.png?v=1', (string) $base->append('abc.png'));
    }

    public function testAppendInsertsSegmentBeforeFragment(): void
    {
        $base = HttpUrl::tryFromString('https://blossom.example.com/blobs#section');

        self::assertNotNull($base);
        self::assertSame('https://blossom.example.com/blobs/abc.png#section', (string) $base->append('abc.png'));
    }

    public function testAppendReturnsValidatedInstance(): void
    {
        $base = HttpUrl::tryFromString('https://blossom.example.com');

        self::assertNotNull($base);
        self::assertTrue($base->append('abc.png')->equals(BlossomFixtures::httpUrl('https://blossom.example.com/abc.png')));
    }

    public function testExposesSchemeHostAndPort(): void
    {
        $url = HttpUrl::tryFromString('HTTPS://Blossom.Example.COM:8443/Path');

        self::assertNotNull($url);
        self::assertSame('https', $url->getScheme());
        self::assertSame('blossom.example.com', $url->getHost());
        self::assertSame(8443, $url->getPort());
    }

    public function testPortIsNullWhenAbsent(): void
    {
        $url = HttpUrl::tryFromString('https://blossom.example.com/path');

        self::assertNotNull($url);
        self::assertNull($url->getPort());
    }

    public function testEqualsComparesNormalisedValue(): void
    {
        $mixedCase = HttpUrl::tryFromString('HTTPS://Blossom.Example.COM/path');
        $lowerCase = HttpUrl::tryFromString('https://blossom.example.com/path');
        $other = HttpUrl::tryFromString('https://other.example.com/path');

        self::assertNotNull($mixedCase);
        self::assertNotNull($lowerCase);
        self::assertNotNull($other);
        self::assertTrue($mixedCase->equals($lowerCase));
        self::assertFalse($mixedCase->equals($other));
    }

    public function testEqualsDistinguishesTrailingSlashVariants(): void
    {
        $withSlash = HttpUrl::tryFromString('https://blossom.example.com/blobs/');
        $withoutSlash = HttpUrl::tryFromString('https://blossom.example.com/blobs');

        self::assertNotNull($withSlash);
        self::assertNotNull($withoutSlash);
        self::assertFalse($withSlash->equals($withoutSlash));
    }
}
