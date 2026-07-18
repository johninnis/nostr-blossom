<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

interface BlossomPolicyInterface
{
    public function allowUpload(PublicKey $actor): ?BlossomFailure;

    public function allowMedia(PublicKey $actor): ?BlossomFailure;

    public function allowList(PublicKey $actor, PublicKey $target): ?BlossomFailure;

    public function allowDelete(PublicKey $actor): ?BlossomFailure;

    public function allowGet(?PublicKey $actor, BlobHash $hash): ?BlossomFailure;
}
