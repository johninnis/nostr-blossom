<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Port;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\Entity\Event;

interface BlobReportStoreInterface
{
    public function save(BlobHash $hash, Event $report): void;
}
