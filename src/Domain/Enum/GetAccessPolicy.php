<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Enum;

enum GetAccessPolicy
{
    case Public;
    case Authenticated;
    case Tenant;
}
