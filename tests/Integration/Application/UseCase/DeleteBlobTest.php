<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\DeleteBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
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

final class DeleteBlobTest extends TestCase
{
    private KeyPair $ownerKeyPair;
    private ServerConfig $config;
    private SignatureServiceInterface $signatureService;
    private BlossomAuthValidator $authValidator;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());
        $this->ownerKeyPair = KeyPair::generate($this->signatureService);
        $this->config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->ownerKeyPair->getPublicKey()],
            maxUploadBytes: 1024 * 1024,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $clock, $this->config->getIdentity());
    }

    public function testDeleteRemovesBytesWhenLastTenantReleases(): void
    {
        $sha256 = hash('sha256', 'to delete');
        $authHeader = $this->buildAuthHeader('delete', $sha256);

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(true);
        $index->method('findByHash')->willReturn(null);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('delete');

        $deleteBlob = new DeleteBlobUseCase(new BlobArchive($store, $index), $this->gate());
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($sha256));

        self::assertNull($result);
    }

    public function testDeleteKeepsBytesWhileAnotherTenantHolds(): void
    {
        $sha256 = hash('sha256', 'shared');
        $authHeader = $this->buildAuthHeader('delete', $sha256);

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(true);
        $index->method('findByHash')->willReturn($this->descriptor($sha256));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        $deleteBlob = new DeleteBlobUseCase(new BlobArchive($store, $index), $this->gate());
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($sha256));

        self::assertNull($result);
    }

    public function testDeleteReturnsErrorWhenTenantDoesNotHoldBlob(): void
    {
        $sha256 = hash('sha256', 'nonexistent');
        $authHeader = $this->buildAuthHeader('delete', $sha256);

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(false);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        $deleteBlob = new DeleteBlobUseCase(new BlobArchive($store, $index), $this->gate());
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($sha256));

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $sha256 = hash('sha256', 'to delete');
        $deleteBlob = new DeleteBlobUseCase(
            new BlobArchive($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->gate(),
        );

        $trusted = $deleteBlob->execute($this->headerWithInvalidSignature($this->ownerKeyPair, 'delete', $sha256), BlossomFixtures::blobHash($sha256));
        $unknown = $deleteBlob->execute($this->headerWithInvalidSignature($stranger, 'delete', $sha256), BlossomFixtures::blobHash($sha256));

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $sha256 = hash('sha256', 'to delete');
        $deleteBlob = new DeleteBlobUseCase(
            new BlobArchive($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->gate(),
        );

        $result = $deleteBlob->execute($this->headerWithInvalidSignature($stranger, 'delete', $sha256), BlossomFixtures::blobHash($sha256));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $sha256 = hash('sha256', 'to delete');
        $deleteBlob = new DeleteBlobUseCase(
            new BlobArchive($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->gate(),
        );

        $result = $deleteBlob->execute($this->buildAuthHeader('delete', $sha256, $stranger), BlossomFixtures::blobHash($sha256));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsInvalidSignatureWhenBindingPasses(): void
    {
        $sha256 = hash('sha256', 'to delete');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'delete', $sha256);

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('deleteForTenant');

        $deleteBlob = new DeleteBlobUseCase(
            new BlobArchive($this->createStub(BlobStoreInterface::class), $index),
            $this->gate(),
        );
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($sha256));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testBlobBindingIsCheckedBeforeSignatureIsVerified(): void
    {
        $deletedHash = hash('sha256', 'the-blob-to-delete');
        $namedHash = hash('sha256', 'a-different-blob');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'delete', $namedHash);

        $deleteBlob = new DeleteBlobUseCase(
            new BlobArchive($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->gate(),
        );
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($deletedHash));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsDeleteWhenAuthEventDoesNotNameTheBlob(): void
    {
        $deletedHash = hash('sha256', 'the-blob-to-delete');
        $namedHash = hash('sha256', 'a-different-blob');
        $authHeader = $this->buildAuthHeader('delete', $namedHash);

        $deleteBlob = new DeleteBlobUseCase(
            new BlobArchive($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->gate(),
        );
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($deletedHash));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function gate(): BlossomAccessGate
    {
        return new BlossomAccessGate($this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()));
    }

    private function descriptor(string $sha256): BlobDescriptor
    {
        return new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256.'.png'),
            sha256: BlossomFixtures::blobHash($sha256),
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );
    }

    private function buildAuthHeader(string $verb, string $hash, ?KeyPair $signer = null): string
    {
        $signer ??= $this->ownerKeyPair;

        $tags = new TagCollection([
            Tag::fromArray([TagType::HASHTAG, $verb]),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
            Tag::fromArray([TagType::SHA256, $hash]),
        ]);

        $event = Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Delete blob'),
            $tags,
        )->sign($signer, $this->signatureService);

        return BlossomFixtures::authHeader($event);
    }

    private function headerWithInvalidSignature(KeyPair $signer, string $verb, string $hash): string
    {
        $event = Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Delete blob'),
            new TagCollection([
                Tag::fromArray([TagType::HASHTAG, $verb]),
                Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
                Tag::fromArray([TagType::SHA256, $hash]),
            ]),
        )->sign($signer, $this->signatureService);

        return BlossomFixtures::authHeaderWithInvalidSignature($event);
    }
}
