<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListQueryTest extends TestCase
{
    public function testDefaultLimit(): void
    {
        $query = new ListQuery();

        self::assertSame(100, $query->getLimit());
        self::assertNull($query->getSince());
        self::assertNull($query->getUntil());
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

    public function testWithFilters(): void
    {
        $query = new ListQuery(since: Timestamp::fromInt(1000), until: Timestamp::fromInt(2000));

        $since = $query->getSince();
        $until = $query->getUntil();
        self::assertNotNull($since);
        self::assertNotNull($until);
        self::assertSame(1000, $since->toInt());
        self::assertSame(2000, $until->toInt());
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

    public function testFromQueryStringParsesValues(): void
    {
        $query = ListQuery::tryFromQueryString('limit=50&since=1000&until=2000');

        self::assertNotNull($query);
        self::assertSame(50, $query->getLimit());
        self::assertSame(1000, $query->getSince()?->toInt());
        self::assertSame(2000, $query->getUntil()?->toInt());
    }

    public function testFromQueryStringDefaultsWhenAbsent(): void
    {
        $query = ListQuery::tryFromQueryString('');

        self::assertNotNull($query);
        self::assertSame(100, $query->getLimit());
        self::assertNull($query->getSince());
        self::assertNull($query->getUntil());
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
