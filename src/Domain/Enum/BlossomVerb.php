<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Enum;

enum BlossomVerb: string
{
    case Upload = 'upload';
    case Media = 'media';
    case Delete = 'delete';
    case List = 'list';
    case Get = 'get';
}
