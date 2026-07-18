<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

final readonly class ServerIdentity
{
    public function __construct(private HttpUrl $baseUrl)
    {
    }

    public function getBaseUrl(): HttpUrl
    {
        return $this->baseUrl;
    }

    public function isThisServer(string $domain): bool
    {
        return $this->baseUrl->getHost() === strtolower($domain);
    }

    public function buildBlobUrl(BlobHash $hash, MimeType $mimeType): HttpUrl
    {
        return $this->baseUrl->append($hash->toHex().$mimeType->getExtension());
    }
}
