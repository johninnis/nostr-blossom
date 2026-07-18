<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Domain\Enum\GetAccessPolicy;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;

final class TenantBlossomPolicyTest extends TestCase
{
    private PublicKey $tenant;
    private PublicKey $stranger;
    private BlobHash $hash;
    private TenantBlossomPolicy $policy;

    protected function setUp(): void
    {
        $this->tenant = BlossomFixtures::publicKey(str_repeat('a', 64));
        $this->stranger = BlossomFixtures::publicKey(str_repeat('b', 64));
        $this->hash = BlossomFixtures::blobHash(hash('sha256', 'blob'));
        $this->policy = new TenantBlossomPolicy($this->config()->getTenantPubkeys());
    }

    public function testAllowsTenantToUpload(): void
    {
        self::assertNull($this->policy->allowUpload($this->tenant));
    }

    public function testAllowsTenantToOptimiseMedia(): void
    {
        self::assertNull($this->policy->allowMedia($this->tenant));
    }

    public function testAllowsTenantToDelete(): void
    {
        self::assertNull($this->policy->allowDelete($this->tenant));
    }

    public function testRejectsStrangerUpload(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy->allowUpload($this->stranger));
    }

    public function testRejectsStrangerMedia(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy->allowMedia($this->stranger));
    }

    public function testRejectsStrangerDelete(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->policy->allowDelete($this->stranger));
    }

    public function testAllowsTenantToListOwnBlobs(): void
    {
        self::assertNull($this->policy->allowList($this->tenant, $this->tenant));
    }

    public function testRejectsTenantListingAnotherPubkey(): void
    {
        $error = $this->policy->allowList($this->tenant, $this->stranger);

        self::assertInstanceOf(AuthorisationFailure::class, $error);
        self::assertSame('Authenticated pubkey may only list its own blobs', $error->getMessage());
    }

    public function testRejectsStrangerListBeforeOwnershipCheck(): void
    {
        $error = $this->policy->allowList($this->stranger, $this->stranger);

        self::assertInstanceOf(AuthorisationFailure::class, $error);
        self::assertSame('Pubkey is not an authorised tenant', $error->getMessage());
    }

    public function testPublicGetAllowsAnonymous(): void
    {
        self::assertNull($this->policy->allowGet(null, $this->hash));
    }

    public function testPublicGetAllowsAnyActor(): void
    {
        self::assertNull($this->policy->allowGet($this->stranger, $this->hash));
    }

    public function testAuthenticatedGetRejectsAnonymous(): void
    {
        $policy = new TenantBlossomPolicy($this->config()->getTenantPubkeys(), GetAccessPolicy::Authenticated);

        self::assertInstanceOf(AuthenticationFailure::class, $policy->allowGet(null, $this->hash));
    }

    public function testAuthenticatedGetAllowsAnyActor(): void
    {
        $policy = new TenantBlossomPolicy($this->config()->getTenantPubkeys(), GetAccessPolicy::Authenticated);

        self::assertNull($policy->allowGet($this->stranger, $this->hash));
    }

    public function testTenantGetRejectsAnonymous(): void
    {
        $policy = new TenantBlossomPolicy($this->config()->getTenantPubkeys(), GetAccessPolicy::Tenant);

        self::assertInstanceOf(AuthenticationFailure::class, $policy->allowGet(null, $this->hash));
    }

    public function testTenantGetRejectsStranger(): void
    {
        $policy = new TenantBlossomPolicy($this->config()->getTenantPubkeys(), GetAccessPolicy::Tenant);

        self::assertInstanceOf(AuthorisationFailure::class, $policy->allowGet($this->stranger, $this->hash));
    }

    public function testTenantGetAllowsTenant(): void
    {
        $policy = new TenantBlossomPolicy($this->config()->getTenantPubkeys(), GetAccessPolicy::Tenant);

        self::assertNull($policy->allowGet($this->tenant, $this->hash));
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
