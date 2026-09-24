<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobReportStoreInterface;
use Innis\Nostr\Blossom\Domain\Failure\BlobReportFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;

final readonly class ReportBlobUseCase
{
    public function __construct(
        private BlobReportStoreInterface $reports,
        private SignatureServiceInterface $signatureService,
    ) {
    }

    public function execute(string $body): ?BlossomFailure
    {
        $event = Event::tryFromJson($body);

        if (null === $event) {
            return BlobReportFailure::malformedEvent();
        }

        if (!$event->getKind()->is(EventKind::REPORTING)) {
            return BlobReportFailure::unexpectedKind();
        }

        $hashes = $this->blobHashes($event->getTags()->getValuesByType(TagType::sha256()));
        if ([] === $hashes) {
            return BlobReportFailure::missingBlobReference();
        }

        if (!$event->verify($this->signatureService)) {
            return BlobReportFailure::invalidSignature();
        }

        foreach ($hashes as $hash) {
            $this->reports->save($hash, $event);
        }

        return null;
    }

    /**
     * @param list<string> $values
     *
     * @return list<BlobHash>
     */
    private function blobHashes(array $values): array
    {
        $byHex = [];
        foreach ($values as $value) {
            $hash = BlobHash::tryFromHex($value);
            if (null !== $hash) {
                $byHex[$hash->toHex()] = $hash;
            }
        }

        return array_values($byHex);
    }
}
