<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\RetrievedBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\Service\TenantOwnedBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\GetBlobUseCase;
use Innis\Nostr\Blossom\Domain\Enum\GetAccessPolicy;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use PHPUnit\Framework\TestCase;

final class GetBlobTest extends TestCase
{
    private SignatureServiceInterface $signatureService;
    private KeyPair $tenantKeyPair;
    private BlossomAuthValidator $authValidator;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $this->tenantKeyPair = KeyPair::generate($this->signatureService);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $clock, $this->tenantConfig()->getIdentity());
    }

    public function testReturnsDescriptorAndPathForExistingBlob(): void
    {
        $sha256 = hash('sha256', 'test content');
        $hash = BlossomFixtures::blobHash($sha256);
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://example.com/'.$sha256.'.png'),
            sha256: $hash,
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('retrievePath')->willReturn('/tmp/blobs/'.$sha256);

        $getBlob = $this->getBlob($store, $index, $this->publicAccess());
        $result = $getBlob->execute($hash);

        self::assertEquals(new RetrievedBlob($descriptor, '/tmp/blobs/'.$sha256), $result);
    }

    public function testPublicReadIgnoresPresentAuthHeader(): void
    {
        $sha256 = hash('sha256', 'public content');
        $hash = BlossomFixtures::blobHash($sha256);
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://example.com/'.$sha256.'.png'),
            sha256: $hash,
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('retrievePath')->willReturn('/tmp/blobs/'.$sha256);

        $getBlob = $this->getBlob($store, $index, $this->publicAccess());
        $result = $getBlob->execute($hash, BlossomFixtures::authHeaderCarrying('not json'));

        self::assertInstanceOf(RetrievedBlob::class, $result);
    }

    public function testReturnsErrorWhenBlobNotInIndex(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'missing'));

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn(null);

        $store = $this->createStub(BlobStoreInterface::class);

        $getBlob = $this->getBlob($store, $index, $this->publicAccess());
        $result = $getBlob->execute($hash);

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testReturnsErrorWhenFileNotOnDisk(): void
    {
        $sha256 = hash('sha256', 'orphaned');
        $hash = BlossomFixtures::blobHash($sha256);
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://example.com/'.$sha256.'.png'),
            sha256: $hash,
            size: 512,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('retrievePath')->willReturn(null);

        $getBlob = $this->getBlob($store, $index, $this->publicAccess());
        $result = $getBlob->execute($hash);

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testDeniesGetWhenAccessRejects(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'private'));

        $result = $this->getBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->refusingAccess())->execute($hash);

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testDeniesGetBeforeLookingUpTheBlobWhenAccessRejects(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'private'));

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('findByHash');

        $this->getBlob($this->createStub(BlobStoreInterface::class), $index, $this->refusingAccess())->execute($hash);
    }

    public function testDeniesGetBeforeTouchingStorageWhenAccessRejects(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'private'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('retrievePath');

        $this->getBlob($store, $this->createStub(BlobIndexInterface::class), $this->refusingAccess())->execute($hash);
    }

    public function testPrivateInstanceRejectsAnonymousGet(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'gated'));

        $result = $this->getBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->tenantAccess())->execute($hash);

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testPrivateInstanceDoesNotLookUpTheBlobForAnonymousGet(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'gated'));

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('findByHash');

        $this->getBlob($this->createStub(BlobStoreInterface::class), $index, $this->tenantAccess())->execute($hash);
    }

    public function testRejectsMalformedAuthHeaderAsUnauthenticated(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'gated'));

        $result = $this->getBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->tenantAccess())
            ->execute($hash, BlossomFixtures::authHeaderCarrying('not json'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsMalformedAuthHeaderBeforeTouchingStorage(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'gated'));

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('findByHash');

        $this->getBlob($this->createStub(BlobStoreInterface::class), $index, $this->tenantAccess())->execute($hash, BlossomFixtures::authHeaderCarrying('not json'));
    }

    public function testPrivateInstanceServesAuthenticatedTenant(): void
    {
        $sha256 = hash('sha256', 'tenant blob');
        $hash = BlossomFixtures::blobHash($sha256);
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://example.com/'.$sha256.'.png'),
            sha256: $hash,
            size: 64,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('retrievePath')->willReturn('/tmp/blobs/'.$sha256);

        $getBlob = $this->getBlob($store, $index, $this->tenantAccess());
        $result = $getBlob->execute($hash, $this->getAuthHeader($this->tenantKeyPair));

        self::assertInstanceOf(RetrievedBlob::class, $result);
    }

    public function testPrivateInstanceRejectsAuthenticatedStranger(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));
        $stranger = KeyPair::generate($this->signatureService);

        $result = $this->getBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->tenantAccess())
            ->execute($hash, $this->getAuthHeader($stranger));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testPrivateInstanceDoesNotLookUpTheBlobForAuthenticatedStranger(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));
        $stranger = KeyPair::generate($this->signatureService);

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('findByHash');

        $this->getBlob($this->createStub(BlobStoreInterface::class), $index, $this->tenantAccess())->execute($hash, $this->getAuthHeader($stranger));
    }

    public function testGatedReadServesBlobNamedInTheTokenScope(): void
    {
        $sha256 = hash('sha256', 'scoped blob');
        $hash = BlossomFixtures::blobHash($sha256);
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://example.com/'.$sha256.'.png'),
            sha256: $hash,
            size: 64,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('retrievePath')->willReturn('/tmp/blobs/'.$sha256);

        $getBlob = $this->getBlob($store, $index, $this->tenantAccess());
        $result = $getBlob->execute($hash, $this->getAuthHeader($this->tenantKeyPair, $hash->toHex()));

        self::assertInstanceOf(RetrievedBlob::class, $result);
    }

    public function testGatedReadRejectsBlobOutsideTheTokenScope(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'requested blob'));

        $result = $this->getBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->tenantAccess())
            ->execute($hash, $this->getAuthHeader($this->tenantKeyPair, hash('sha256', 'a different blob')));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testGatedReadDoesNotLookUpBlobOutsideTheTokenScope(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'requested blob'));

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('findByHash');

        $this->getBlob($this->createStub(BlobStoreInterface::class), $index, $this->tenantAccess())
            ->execute($hash, $this->getAuthHeader($this->tenantKeyPair, hash('sha256', 'a different blob')));
    }

    public function testGatedReadDoesNotTouchStorageForBlobOutsideTheTokenScope(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'requested blob'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('retrievePath');

        $this->getBlob($store, $this->createStub(BlobIndexInterface::class), $this->tenantAccess())
            ->execute($hash, $this->getAuthHeader($this->tenantKeyPair, hash('sha256', 'a different blob')));
    }

    public function testGatedReadRejectsOutOfScopeTokenBeforeSignatureVerification(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'requested blob'));

        $index = $this->createStub(BlobIndexInterface::class);
        $store = $this->createStub(BlobStoreInterface::class);

        $getBlob = $this->getBlob($store, $index, $this->tenantAccess());
        $result = $getBlob->execute($hash, $this->invalidlySignedGetHeader($this->tenantKeyPair, hash('sha256', 'a different blob')));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testTenantOwnedReadVerifiesSignatureBeforeOwnershipCheck(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('ownsBlob');

        $this->tenantOwnedRead($this->createStub(BlobStoreInterface::class), $index)->execute($hash, $this->invalidlySignedGetHeader($this->tenantKeyPair));
    }

    public function testTenantOwnedReadWithAnInvalidSignatureDoesNotLookUpTheBlob(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('findByHash');

        $this->tenantOwnedRead($this->createStub(BlobStoreInterface::class), $index)->execute($hash, $this->invalidlySignedGetHeader($this->tenantKeyPair));
    }

    public function testTenantOwnedReadWithAnInvalidSignatureDoesNotTouchStorage(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('retrievePath');

        $this->tenantOwnedRead($store, $this->createStub(BlobIndexInterface::class))->execute($hash, $this->invalidlySignedGetHeader($this->tenantKeyPair));
    }

    public function testTenantOwnedReadWithAnInvalidSignatureIsRefusedAsUnauthenticated(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));

        $result = $this->tenantOwnedRead($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class))
            ->execute($hash, $this->invalidlySignedGetHeader($this->tenantKeyPair));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testGatedReadGivesTheSameRefusalToAnInvalidSignatureWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'tenant blob'));
        $stranger = KeyPair::generate($this->signatureService);
        $getBlob = $this->getBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->tenantAccess());

        $trusted = $getBlob->execute($hash, $this->invalidlySignedGetHeader($this->tenantKeyPair));
        $unknown = $getBlob->execute($hash, $this->invalidlySignedGetHeader($stranger));

        self::assertEquals($trusted, $unknown);
    }

    private function getBlob(BlobStoreInterface $store, BlobIndexInterface $index, BlossomPolicyInterface $policy): GetBlobUseCase
    {
        return new GetBlobUseCase(new BlobArchive($store, $index), new BlossomAccessGate($this->authValidator, $policy));
    }

    private function tenantOwnedRead(BlobStoreInterface $store, BlobIndexInterface $index): GetBlobUseCase
    {
        return $this->getBlob($store, $index, new TenantOwnedBlossomPolicy($this->tenantAccess(), $index));
    }

    private function refusingAccess(): BlossomPolicyInterface
    {
        $policy = $this->createStub(BlossomPolicyInterface::class);
        $policy->method('allowGet')->willReturn(AuthorisationFailure::notTenant());

        return $policy;
    }

    private function publicAccess(): BlossomPolicyInterface
    {
        return new TenantBlossomPolicy($this->tenantConfig()->getTenantPubkeys(), GetAccessPolicy::Public);
    }

    private function tenantAccess(): BlossomPolicyInterface
    {
        return new TenantBlossomPolicy($this->tenantConfig()->getTenantPubkeys(), GetAccessPolicy::Tenant);
    }

    private function tenantConfig(): ServerConfig
    {
        return BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->tenantKeyPair->getPublicKey()],
            maxUploadBytes: 1024,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
    }

    private function getAuthHeader(KeyPair $keyPair, ?string $xHash = null): string
    {
        return BlossomFixtures::authHeader($this->getAuthEvent($keyPair, $xHash));
    }

    private function invalidlySignedGetHeader(KeyPair $keyPair, ?string $xHash = null): string
    {
        return BlossomFixtures::authHeaderWithInvalidSignature($this->getAuthEvent($keyPair, $xHash));
    }

    private function getAuthEvent(KeyPair $keyPair, ?string $xHash = null): Event
    {
        $tags = [
            Tag::fromArray([TagType::HASHTAG, 'get']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ];

        if (null !== $xHash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $xHash]);
        }

        return Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($keyPair, $this->signatureService);
    }
}
