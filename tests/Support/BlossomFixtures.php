<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Support;

use Innis\Nostr\Blossom\Domain\Collection\AllowedMimeTypes;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerIdentity;
use Innis\Nostr\Blossom\Domain\ValueObject\TenantPubkeys;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use InvalidArgumentException;

final class BlossomFixtures
{
    public static function blobHash(string $hex): BlobHash
    {
        return BlobHash::tryFromHex($hex) ?? throw new InvalidArgumentException(sprintf('not a valid blob hash: %s', $hex));
    }

    public static function publicKey(string $hex): PublicKey
    {
        return PublicKey::tryFromHex($hex) ?? throw new InvalidArgumentException(sprintf('not a valid pubkey: %s', $hex));
    }

    public static function httpUrl(string $url): HttpUrl
    {
        return HttpUrl::tryFromString($url) ?? throw new InvalidArgumentException(sprintf('not a valid http url: %s', $url));
    }

    public static function mimeType(string $value): MimeType
    {
        return MimeType::tryFromString($value) ?? throw new InvalidArgumentException(sprintf('not a valid mime type: %s', $value));
    }

    /**
     * @param list<PublicKey> $tenantPubkeys
     * @param list<string>    $allowedMimeTypes
     */
    public static function serverConfig(
        array $tenantPubkeys,
        int $maxUploadBytes,
        array $allowedMimeTypes,
        string $baseUrl,
    ): ServerConfig {
        return new ServerConfig(
            new TenantPubkeys($tenantPubkeys),
            self::uploadConstraints($maxUploadBytes, $allowedMimeTypes),
            self::serverIdentity($baseUrl),
        );
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public static function uploadConstraints(int $maxUploadBytes, array $allowedMimeTypes): UploadConstraints
    {
        return new UploadConstraints($maxUploadBytes, new AllowedMimeTypes(array_map(self::mimeType(...), $allowedMimeTypes)));
    }

    public static function serverIdentity(string $baseUrl): ServerIdentity
    {
        return new ServerIdentity(self::httpUrl($baseUrl));
    }
}
