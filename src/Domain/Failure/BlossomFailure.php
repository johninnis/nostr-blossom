<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\Failure;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;

// Deliberate sealed failure family: a returned value, not a thrown exception, and no BlossomException base — see ADR-0002
abstract readonly class BlossomFailure
{
    protected function __construct(private string $message)
    {
    }

    final public function getMessage(): string
    {
        return $this->message;
    }

    abstract public function category(): BlossomFailureCategory;
}
