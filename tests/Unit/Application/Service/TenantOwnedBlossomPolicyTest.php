<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\Service\TenantOwnedBlossomPolicy;
use Innis\Nostr\Blossom\Domain\Enum\GetAccessPolicy;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;

final class TenantOwnedBlossomPolicyTest extends TestCase
{
    private PublicKey $tenant;
    private PublicKey $stranger;
    private BlobHash $hash;

    protected function setUp(): void
    {
        $this->tenant = BlossomFixtures::publicKey(str_repeat('a', 64));
        $this->stranger = BlossomFixtures::publicKey(str_repeat('b', 64));
        $this->hash = BlossomFixtures::blobHash(hash('sha256', 'blob'));
    }

    public function testRejectsAnonymousGet(): void
    {
        $policy = $this->policy($this->createStub(BlobIndexInterface::class));

        self::assertInstanceOf(AuthenticationFailure::class, $policy->allowGet(null, $this->hash));
    }

    public function testAllowsTenantWhoOwnsBlob(): void
    {
        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('ownsBlob')->willReturn(true);

        self::assertNull($this->policy($index)->allowGet($this->tenant, $this->hash));
    }

    public function testRejectsTenantWhoDoesNotOwnBlob(): void
    {
        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('ownsBlob')->willReturn(false);

        $error = $this->policy($index)->allowGet($this->tenant, $this->hash);

        self::assertInstanceOf(AuthorisationFailure::class, $error);
        self::assertSame('Authenticated pubkey may only access its own blobs', $error->getMessage());
    }

    public function testRejectsStrangerBeforeOwnershipCheck(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('ownsBlob');

        $error = $this->policy($index)->allowGet($this->stranger, $this->hash);

        self::assertInstanceOf(AuthorisationFailure::class, $error);
        self::assertSame('Pubkey is not an authorised tenant', $error->getMessage());
    }

    public function testDelegatesWriteVerbsToTenantPolicy(): void
    {
        $policy = $this->policy($this->createStub(BlobIndexInterface::class));

        self::assertNull($policy->allowUpload($this->tenant));
        self::assertNull($policy->allowMedia($this->tenant));
        self::assertNull($policy->allowList($this->tenant, $this->tenant));
        self::assertNull($policy->allowDelete($this->tenant));

        self::assertInstanceOf(AuthorisationFailure::class, $policy->allowUpload($this->stranger));
        self::assertInstanceOf(AuthorisationFailure::class, $policy->allowMedia($this->stranger));
        self::assertInstanceOf(AuthorisationFailure::class, $policy->allowList($this->stranger, $this->stranger));
        self::assertInstanceOf(AuthorisationFailure::class, $policy->allowDelete($this->stranger));
    }

    private function policy(BlobIndexInterface $index): TenantOwnedBlossomPolicy
    {
        return new TenantOwnedBlossomPolicy(new TenantBlossomPolicy($this->config()->getTenantPubkeys(), GetAccessPolicy::Tenant), $index);
    }

    private function config(): ServerConfig
    {
        return BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->tenant],
            maxUploadBytes: 1024,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
    }
}
