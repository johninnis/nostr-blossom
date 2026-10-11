<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListQueryTest extends TestCase
{
    public function testDefaultLimit(): void
    {
        self::assertSame(100, new ListQuery()->getLimit());
    }

    public function testHasNoSinceByDefault(): void
    {
        self::assertNull(new ListQuery()->getSince());
    }

    public function testHasNoUntilByDefault(): void
    {
        self::assertNull(new ListQuery()->getUntil());
    }

    public function testHasNoCursorByDefault(): void
    {
        self::assertNull(new ListQuery()->getCursor());
    }

    public function testCustomLimit(): void
    {
        $query = new ListQuery(limit: 50);

        self::assertSame(50, $query->getLimit());
    }

    public function testAcceptsMaximumLimit(): void
    {
        $query = new ListQuery(limit: 1000);

        self::assertSame(1000, $query->getLimit());
    }

    public function testRejectsLimitAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ListQuery(limit: 5000);
    }

    public function testCarriesTheSinceFilter(): void
    {
        self::assertSame(1000, new ListQuery(since: Timestamp::fromInt(1000), until: Timestamp::fromInt(2000))->getSince()?->toInt());
    }

    public function testCarriesTheUntilFilter(): void
    {
        self::assertSame(2000, new ListQuery(since: Timestamp::fromInt(1000), until: Timestamp::fromInt(2000))->getUntil()?->toInt());
    }

    public function testRejectsLimitBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ListQuery(limit: 0);
    }

    public function testRejectsSinceAfterUntil(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ListQuery(since: Timestamp::fromInt(2000), until: Timestamp::fromInt(1000));
    }

    public function testFromQueryStringParsesTheLimit(): void
    {
        self::assertSame(50, ListQuery::tryFromQueryString('limit=50&since=1000&until=2000')?->getLimit());
    }

    public function testFromQueryStringParsesTheSince(): void
    {
        self::assertSame(1000, ListQuery::tryFromQueryString('limit=50&since=1000&until=2000')?->getSince()?->toInt());
    }

    public function testFromQueryStringParsesTheUntil(): void
    {
        self::assertSame(2000, ListQuery::tryFromQueryString('limit=50&since=1000&until=2000')?->getUntil()?->toInt());
    }

    public function testCarriesACursor(): void
    {
        self::assertSame(str_repeat('a', 64), new ListQuery(cursor: BlossomFixtures::blobHash(str_repeat('a', 64)))->getCursor()?->toHex());
    }

    public function testFromQueryStringParsesCursor(): void
    {
        self::assertSame(str_repeat('b', 64), ListQuery::tryFromQueryString('cursor='.str_repeat('b', 64))?->getCursor()?->toHex());
    }

    public function testFromQueryStringRejectsMalformedCursor(): void
    {
        self::assertNull(ListQuery::tryFromQueryString('cursor=not-a-hash'));
    }

    public function testFromQueryStringDefaultsWhenAbsent(): void
    {
        self::assertEquals(new ListQuery(), ListQuery::tryFromQueryString(''));
    }

    public function testFromQueryStringRejectsNonNumericLimit(): void
    {
        self::assertNull(ListQuery::tryFromQueryString('limit=lots'));
    }

    public function testFromQueryStringRejectsOutOfRangeLimit(): void
    {
        self::assertNull(ListQuery::tryFromQueryString('limit=5000'));
    }

    public function testFromQueryStringRejectsMalformedSince(): void
    {
        self::assertNull(ListQuery::tryFromQueryString('since=yesterday'));
    }

    public function testFromQueryStringRejectsMalformedUntil(): void
    {
        self::assertNull(ListQuery::tryFromQueryString('until=tomorrow'));
    }

    public function testFromQueryStringRejectsSinceAfterUntil(): void
    {
        self::assertNull(ListQuery::tryFromQueryString('since=2000&until=1000'));
    }
}
