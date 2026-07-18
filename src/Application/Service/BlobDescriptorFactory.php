<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\StorableBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerIdentity;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\FileMetadata;

final readonly class BlobDescriptorFactory
{
    public function __construct(
        private ServerIdentity $identity,
        private ClockInterface $clock,
    ) {
    }

    public function create(StorableBlob $storable): BlobDescriptor
    {
        $blob = $storable->getBlob();
        $mimeType = $storable->getMimeType();
        $url = $this->identity->buildBlobUrl($blob->getHash(), $mimeType);

        return new BlobDescriptor(
            url: $url,
            sha256: $blob->getHash(),
            size: $blob->getSize(),
            type: $mimeType,
            uploaded: $this->clock->now(),
            nip94: $this->buildNip94($storable, $url),
        );
    }

    private function buildNip94(StorableBlob $storable, HttpUrl $url): ?FileMetadata
    {
        $blob = $storable->getBlob();
        $originalHash = $storable->getOriginalHash();
        $media = $blob->getMedia();
        $hasMedia = null !== $media && !$media->isEmpty();
        if (!$hasMedia && null === $originalHash) {
            return null;
        }

        return new FileMetadata(
            url: (string) $url,
            mimeType: (string) $storable->getMimeType(),
            hash: $blob->getHash()->toHex(),
            originalHash: $originalHash?->toHex(),
            size: $blob->getSize(),
            dimensions: $media?->getDimensions(),
            blurhash: $media?->getBlurhash(),
        );
    }
}
