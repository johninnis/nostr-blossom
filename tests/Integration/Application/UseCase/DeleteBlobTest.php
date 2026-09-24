<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Service\BlobRemover;
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
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
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

        $deleteBlob = new DeleteBlobUseCase(new BlobRemover($store, $index), $this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()));
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

        $deleteBlob = new DeleteBlobUseCase(new BlobRemover($store, $index), $this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()));
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

        $deleteBlob = new DeleteBlobUseCase(new BlobRemover($store, $index), $this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()));
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($sha256));

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testAdmissionRejectsUnauthorisedDeleterBeforeSignatureIsVerified(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $sha256 = hash('sha256', 'to delete');
        $authHeader = $this->headerWithInvalidSignature($stranger, 'delete', $sha256);

        $deleteBlob = new DeleteBlobUseCase(
            new BlobRemover($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->authValidator,
            new TenantBlossomPolicy($this->config->getTenantPubkeys()),
        );
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($sha256));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsInvalidSignatureWhenBindingPasses(): void
    {
        $sha256 = hash('sha256', 'to delete');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'delete', $sha256);

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('deleteForTenant');

        $deleteBlob = new DeleteBlobUseCase(
            new BlobRemover($this->createStub(BlobStoreInterface::class), $index),
            $this->authValidator,
            new TenantBlossomPolicy($this->config->getTenantPubkeys()),
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
            new BlobRemover($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->authValidator,
            new TenantBlossomPolicy($this->config->getTenantPubkeys()),
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
            new BlobRemover($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class)),
            $this->authValidator,
            new TenantBlossomPolicy($this->config->getTenantPubkeys()),
        );
        $result = $deleteBlob->execute($authHeader, BlossomFixtures::blobHash($deletedHash));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
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

    private function buildAuthHeader(string $verb, string $hash): string
    {
        $tags = new TagCollection([
            new Tag(TagType::hashtag(), [$verb]),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
            new Tag(TagType::sha256(), [$hash]),
        ]);

        $event = RumourFactory::createCustomKind(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Delete blob'),
            $tags,
        )->sign($this->ownerKeyPair, $this->signatureService);

        return 'Nostr '.base64_encode((string) json_encode($event->toArray()));
    }

    private function headerWithInvalidSignature(KeyPair $signer, string $verb, string $hash): string
    {
        $event = RumourFactory::createCustomKind(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Delete blob'),
            new TagCollection([
                new Tag(TagType::hashtag(), [$verb]),
                new Tag(TagType::expiration(), [(string) (time() + 3600)]),
                new Tag(TagType::sha256(), [$hash]),
            ]),
        )->sign($signer, $this->signatureService);

        $array = $event->toArray();
        $array['sig'] = str_repeat('0', 128);

        return 'Nostr '.base64_encode((string) json_encode($array));
    }
}
