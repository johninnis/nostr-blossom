<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\RemoteBlobFetcherInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\MirrorBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\RemoteFetchFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
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
use RuntimeException;

final class MirrorBlobTest extends TestCase
{
    private KeyPair $ownerKeyPair;
    private ServerConfig $config;
    private SignatureServiceInterface $signatureService;
    private BlossomAuthValidator $authValidator;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $this->ownerKeyPair = KeyPair::generate($this->signatureService);
        $this->config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->ownerKeyPair->getPublicKey()],
            maxUploadBytes: 1024 * 1024,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $this->clock(), $this->config->getIdentity());
    }

    public function testMirrorStoresTheFetchedBlob(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');

        $this->mirrorAuthorisedBlob($store, $this->createStub(BlobIndexInterface::class));
    }

    public function testMirrorIndexesTheFetchedBlob(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::once())->method('save');

        $this->mirrorAuthorisedBlob($this->createStub(BlobStoreInterface::class), $index);
    }

    public function testMirrorDescribesTheFetchedBlobByItsHash(): void
    {
        $result = $this->mirrorAuthorisedBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class));

        self::assertSame(hash('sha256', 'mirrored'), BlossomFixtures::descriptorIn($result)->getSha256()->toHex());
    }

    public function testRejectsDisallowedMimeType(): void
    {
        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $this->mirrorDisallowedMimeType($this->createStub(BlobStoreInterface::class)));
    }

    public function testDoesNotStoreDisallowedMimeType(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->mirrorDisallowedMimeType($store);
    }

    public function testDiscardsFetchedFileOfDisallowedMimeType(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->mirrorDisallowedMimeType($store);
    }

    public function testRejectsFailedFetch(): void
    {
        $fetcher = $this->createStub(RemoteBlobFetcherInterface::class);
        $fetcher->method('fetch')->willThrowException(new RuntimeException('connection refused'));

        $store = $this->createStub(BlobStoreInterface::class);
        $mirror = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $fetcher);
        $result = $mirror->execute($this->authHeader(), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(RemoteFetchFailure::class, $result);
    }

    public function testRejectsUnauthenticatedRequest(): void
    {
        $result = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->createStub(RemoteBlobFetcherInterface::class))
            ->execute('', BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsUnauthenticatedRequestBeforeFetching(): void
    {
        $fetcher = $this->createMock(RemoteBlobFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $fetcher)
            ->execute('', BlossomFixtures::httpUrl('https://remote.test/blob'));
    }

    public function testRejectsBlobNotAuthorisedByHashTag(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->mirrorBlobTheTokenDoesNotName($this->createStub(BlobStoreInterface::class)));
    }

    public function testDoesNotStoreBlobNotAuthorisedByHashTag(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->mirrorBlobTheTokenDoesNotName($store);
    }

    public function testDiscardsFetchedBlobNotAuthorisedByHashTag(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->mirrorBlobTheTokenDoesNotName($store);
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'mirrored');
        $mirror = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->inspector($hash, 2048), $this->fetcher('/tmp/mirror', 'image/png'));

        $trusted = $mirror->execute($this->invalidlySignedHeader($hash), BlossomFixtures::httpUrl('https://remote.test/blob'));
        $unknown = $mirror->execute($this->invalidlySignedHeader($hash, $stranger), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'mirrored');
        $mirror = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->inspector($hash, 2048), $this->fetcher('/tmp/mirror', 'image/png'));

        $result = $mirror->execute($this->invalidlySignedHeader($hash, $stranger), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'mirrored');
        $mirror = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->inspector($hash, 2048), $this->fetcher('/tmp/mirror', 'image/png'));

        $result = $mirror->execute($this->authHeader($hash, $stranger), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function mirrorAuthorisedBlob(BlobStoreInterface $store, BlobIndexInterface $index): BlobDescriptor|BlossomFailure
    {
        $hash = hash('sha256', 'mirrored');

        return $this->useCase($store, $index, $this->inspector($hash, 2048), $this->fetcher('/tmp/mirror', 'image/png'))
            ->execute($this->authHeader($hash), BlossomFixtures::httpUrl('https://remote.test/blob'));
    }

    private function mirrorDisallowedMimeType(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'gif'), 2048), $this->fetcher('/tmp/mirror', 'image/gif'))
            ->execute($this->authHeader(), BlossomFixtures::httpUrl('https://remote.test/blob'));
    }

    private function mirrorBlobTheTokenDoesNotName(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'fetched'), 2048), $this->fetcher('/tmp/mirror', 'image/png'))
            ->execute($this->authHeader(hash('sha256', 'authorised')), BlossomFixtures::httpUrl('https://remote.test/blob'));
    }

    private function useCase(BlobStoreInterface $store, BlobIndexInterface $index, BlobInspectorInterface $inspector, RemoteBlobFetcherInterface $fetcher): MirrorBlobUseCase
    {
        $gate = new BlossomAccessGate($this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()));
        $ingestor = new BlobIngestor(new BlobValidator($inspector, $this->config->getUploadConstraints(), $gate), new BlobDescriptorFactory($this->config->getIdentity(), $this->clock()), new BlobArchive($store, $index));

        return new MirrorBlobUseCase($fetcher, $gate, $ingestor);
    }

    private function inspector(string $hash, int $size): BlobInspectorInterface
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($hash), $size));

        return $inspector;
    }

    private function fetcher(string $path, string $mimeType): RemoteBlobFetcherInterface
    {
        $fetcher = $this->createStub(RemoteBlobFetcherInterface::class);
        $fetcher->method('fetch')->willReturn(new PendingBlob($path, BlossomFixtures::mimeType($mimeType)));

        return $fetcher;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());

        return $clock;
    }

    private function authHeader(?string $hash = null, ?KeyPair $signer = null): string
    {
        return BlossomFixtures::authHeader($this->authEvent($hash, $signer ?? $this->ownerKeyPair));
    }

    private function invalidlySignedHeader(string $hash, ?KeyPair $signer = null): string
    {
        return BlossomFixtures::authHeaderWithInvalidSignature($this->authEvent($hash, $signer ?? $this->ownerKeyPair));
    }

    private function authEvent(?string $hash, KeyPair $signer): Event
    {
        $tags = [
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $hash]);
        }

        return Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Mirror blob'),
            new TagCollection($tags),
        )->sign($signer, $this->signatureService);
    }
}
