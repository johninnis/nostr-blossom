<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Override;

final readonly class AuthenticationFailure extends BlossomFailure
{
    public static function missingHeader(): self
    {
        return new self('Missing Authorization header');
    }

    public static function headerTooLong(): self
    {
        return new self('Authorization header exceeds maximum length');
    }

    public static function malformedHeader(): self
    {
        return new self('Malformed Nostr authorization');
    }

    public static function invalidEncoding(): self
    {
        return new self('Authorization header is not valid base64');
    }

    public static function invalidJson(): self
    {
        return new self('Authorization header is not valid JSON');
    }

    public static function invalidEvent(): self
    {
        return new self('Authorization header does not contain a valid event');
    }

    public static function invalidSignature(): self
    {
        return new self('Invalid event signature');
    }

    public static function invalidKind(): self
    {
        return new self(sprintf('Event kind must be %d', EventKind::BLOSSOM_BLOB));
    }

    public static function unreasonableCreatedAt(): self
    {
        return new self('Authorization event created_at is too far from the current time');
    }

    public static function missingExpiration(): self
    {
        return new self('Authorization event is missing an expiration tag');
    }

    public static function invalidExpiration(): self
    {
        return new self('Authorization event has an invalid expiration tag');
    }

    public static function expired(): self
    {
        return new self('Authorization event has expired');
    }

    public static function wrongVerb(string $expected, string $actual): self
    {
        return new self(sprintf('Authorization verb must be "%s", got "%s"', $expected, $actual));
    }

    /**
     * @param list<string> $named
     */
    public static function ambiguousVerb(array $named): self
    {
        return new self(sprintf('Authorization event must name exactly one verb, got "%s"', implode('", "', $named)));
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::Authentication;
    }
}
