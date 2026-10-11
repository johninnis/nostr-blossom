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
        self::assertNull($this->policy($this->ownershipIndex(true))->allowGet($this->tenant, $this->hash));
    }

    public function testRejectsTenantWhoDoesNotOwnBlob(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy($this->ownershipIndex(false))->allowGet($this->tenant, $this->hash));
    }

    public function testExplainsThatATenantMayOnlyAccessItsOwnBlobs(): void
    {
        self::assertSame('Authenticated pubkey may only access its own blobs', $this->policy($this->ownershipIndex(false))->allowGet($this->tenant, $this->hash)?->getMessage());
    }

    public function testRejectsStrangerBeforeOwnershipCheck(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy($this->createStub(BlobIndexInterface::class))->allowGet($this->stranger, $this->hash));
    }

    public function testExplainsThatAStrangerIsNotATenant(): void
    {
        self::assertSame('Pubkey is not an authorised tenant', $this->policy($this->createStub(BlobIndexInterface::class))->allowGet($this->stranger, $this->hash)?->getMessage());
    }

    public function testNeverChecksOwnershipForAStranger(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('ownsBlob');

        $this->policy($index)->allowGet($this->stranger, $this->hash);
    }

    public function testDelegatesTenantUploadToTenantPolicy(): void
    {
        self::assertNull($this->policy($this->createStub(BlobIndexInterface::class))->allowUpload($this->tenant));
    }

    public function testDelegatesTenantMediaToTenantPolicy(): void
    {
        self::assertNull($this->policy($this->createStub(BlobIndexInterface::class))->allowMedia($this->tenant));
    }

    public function testDelegatesTenantListToTenantPolicy(): void
    {
        self::assertNull($this->policy($this->createStub(BlobIndexInterface::class))->allowList($this->tenant, $this->tenant));
    }

    public function testDelegatesTenantDeleteToTenantPolicy(): void
    {
        self::assertNull($this->policy($this->createStub(BlobIndexInterface::class))->allowDelete($this->tenant));
    }

    public function testDelegatesStrangerUploadRefusalToTenantPolicy(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy($this->createStub(BlobIndexInterface::class))->allowUpload($this->stranger));
    }

    public function testDelegatesStrangerMediaRefusalToTenantPolicy(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy($this->createStub(BlobIndexInterface::class))->allowMedia($this->stranger));
    }

    public function testDelegatesStrangerListRefusalToTenantPolicy(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy($this->createStub(BlobIndexInterface::class))->allowList($this->stranger, $this->stranger));
    }

    public function testDelegatesStrangerDeleteRefusalToTenantPolicy(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy($this->createStub(BlobIndexInterface::class))->allowDelete($this->stranger));
    }

    private function ownershipIndex(bool $owns): BlobIndexInterface
    {
        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('ownsBlob')->willReturn($owns);

        return $index;
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
