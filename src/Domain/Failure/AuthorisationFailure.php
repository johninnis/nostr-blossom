<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Override;

final readonly class AuthorisationFailure extends BlossomFailure
{
    public static function notTenant(): self
    {
        return new self('Pubkey is not an authorised tenant');
    }

    public static function mayOnlyListOwnBlobs(): self
    {
        return new self('Authenticated pubkey may only list its own blobs');
    }

    public static function mayOnlyAccessOwnBlobs(): self
    {
        return new self('Authenticated pubkey may only access its own blobs');
    }

    public static function blobNotAuthorised(): self
    {
        return new self('Authorization event does not authorise this blob');
    }

    public static function serverNotAuthorised(): self
    {
        return new self('Authorization event does not authorise this server');
    }

    #[Override]
    public function category(): BlossomFailureCategory
    {
        return BlossomFailureCategory::Authorisation;
    }
}
