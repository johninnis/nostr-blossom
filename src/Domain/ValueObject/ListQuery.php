<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use InvalidArgumentException;

final readonly class ListQuery
{
    private const int DEFAULT_LIMIT = 100;
    private const int MAX_LIMIT = 1000;

    public function __construct(
        private int $limit = self::DEFAULT_LIMIT,
        private ?Timestamp $since = null,
        private ?Timestamp $until = null,
    ) {
        // Deliberate: a query specification holds no result set, so an out-of-range limit is rejected, not clamped — see ADR-0005
        if (!self::isValidLimit($this->limit)) {
            throw new InvalidArgumentException(sprintf('limit must be between 1 and %d, got %d', self::MAX_LIMIT, $this->limit));
        }

        if (!self::areBoundsInOrder($since, $until)) {
            throw new InvalidArgumentException('since cannot be after until');
        }
    }

    public static function tryFromQueryString(string $query): ?self
    {
        parse_str($query, $params);

        $since = null;
        if (isset($params['since'])) {
            $since = self::parseTimestamp($params['since']);
            if (null === $since) {
                return null;
            }
        }

        $until = null;
        if (isset($params['until'])) {
            $until = self::parseTimestamp($params['until']);
            if (null === $until) {
                return null;
            }
        }

        $limit = null;
        if (isset($params['limit'])) {
            $limit = self::parseInt($params['limit']);
            if (null === $limit || !self::isValidLimit($limit)) {
                return null;
            }
        }

        if (!self::areBoundsInOrder($since, $until)) {
            return null;
        }

        return new self($limit ?? self::DEFAULT_LIMIT, $since, $until);
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getSince(): ?Timestamp
    {
        return $this->since;
    }

    public function getUntil(): ?Timestamp
    {
        return $this->until;
    }

    private static function isValidLimit(int $limit): bool
    {
        return $limit >= 1 && $limit <= self::MAX_LIMIT;
    }

    private static function areBoundsInOrder(?Timestamp $since, ?Timestamp $until): bool
    {
        return null === $since || null === $until || !$since->isAfter($until);
    }

    private static function parseTimestamp(mixed $value): ?Timestamp
    {
        return is_string($value) ? Timestamp::tryFromDecimalString($value) : null;
    }

    private static function parseInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };
    }
}
