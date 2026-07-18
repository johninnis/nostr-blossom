<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Enum;

enum BlossomFailureCategory
{
    case Authentication;
    case Authorisation;
    case NotFound;
    case PayloadTooLarge;
    case UnsupportedMediaType;
    case MalformedRequest;
    case UpstreamFailure;
    case Internal;

    // Deliberate: the BUD-pinned status is part of the protocol contract, not a transport detail — see ADR-0007
    public function httpStatus(): int
    {
        return match ($this) {
            self::Authentication => 401,
            self::Authorisation => 403,
            self::NotFound => 404,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::MalformedRequest => 400,
            self::UpstreamFailure => 502,
            self::Internal => 500,
        };
    }
}
