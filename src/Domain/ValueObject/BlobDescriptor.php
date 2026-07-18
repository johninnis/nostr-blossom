<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Content\FileMetadata;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use InvalidArgumentException;
use JsonSerializable;
use Override;

final readonly class BlobDescriptor implements JsonSerializable
{
    public function __construct(
        private HttpUrl $url,
        private BlobHash $sha256,
        private int $size,
        private MimeType $type,
        private Timestamp $uploaded,
        private ?FileMetadata $nip94 = null,
    ) {
        // Deliberate: a byte size stays a plain int; this non-negative guard is duplicated across the size-bearing value objects by design, not shared through a byte-size value object — see ADR-0022
        if ($size < 0) {
            throw new InvalidArgumentException('size cannot be negative');
        }
    }

    public function getUrl(): HttpUrl
    {
        return $this->url;
    }

    public function getSha256(): BlobHash
    {
        return $this->sha256;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getType(): MimeType
    {
        return $this->type;
    }

    public function getUploaded(): Timestamp
    {
        return $this->uploaded;
    }

    public function getNip94(): ?FileMetadata
    {
        return $this->nip94;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'url' => (string) $this->url,
            'sha256' => $this->sha256->toHex(),
            'size' => $this->size,
            'type' => (string) $this->type,
            'uploaded' => $this->uploaded->toInt(),
        ];

        if (null !== $this->nip94) {
            $data['nip94'] = $this->nip94->toTags()->toJsonArray();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
