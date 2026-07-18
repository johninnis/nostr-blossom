<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;

final class ServerConfigTest extends TestCase
{
    public function testExposesItsTenantPubkeys(): void
    {
        $first = BlossomFixtures::publicKey(str_repeat('a', 64));
        $second = BlossomFixtures::publicKey(str_repeat('b', 64));

        $config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$first, $second],
            maxUploadBytes: 1024,
            allowedMimeTypes: [],
            baseUrl: 'https://example.com',
        );

        self::assertSame(
            [$first->toHex(), $second->toHex()],
            $config->getTenantPubkeys()->toHexes(),
        );
    }

    public function testExposesItsUploadConstraints(): void
    {
        $config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->pubkey()],
            maxUploadBytes: 4096,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://example.com',
        );

        self::assertSame(4096, $config->getUploadConstraints()->getMaxUploadBytes());
    }

    public function testExposesItsIdentity(): void
    {
        $config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->pubkey()],
            maxUploadBytes: 1024,
            allowedMimeTypes: [],
            baseUrl: 'https://example.com',
        );

        self::assertTrue($config->getIdentity()->isThisServer('example.com'));
    }

    private function pubkey(): PublicKey
    {
        return BlossomFixtures::publicKey(str_repeat('a', 64));
    }
}
