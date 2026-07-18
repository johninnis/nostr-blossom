<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\Entity\Event;

// Deliberate: one cohesive auth-event contract — the pure x-tag binding checks stay here beside parse/verify, not extracted or segregated though they use none of the validator's ports — see ADR-0021
interface BlossomAuthValidatorInterface
{
    public function parse(BlossomVerb $verb, string $authHeader): Event|BlossomFailure;

    public function verify(Event $event): ?BlossomFailure;

    public function requireAuthorisedBlob(Event $event, BlobHash $hash): ?BlossomFailure;

    public function requireBlobInScope(Event $event, BlobHash $hash): ?BlossomFailure;
}
