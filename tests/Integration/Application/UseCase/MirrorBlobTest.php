<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\RemoteBlobFetcherInterface;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\MirrorBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\RemoteFetchFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
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

    public function testSuccessfulMirror(): void
    {
        $hash = hash('sha256', 'mirrored');

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::once())->method('save');

        $mirror = $this->useCase($store, $index, $this->inspector($hash, 2048), $this->fetcher('/tmp/mirror', 'image/png'));
        $result = $mirror->execute($this->authHeader($hash), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame($hash, $result->getSha256()->toHex());
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->expects(self::once())->method('discard');

        $mirror = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'gif'), 2048), $this->fetcher('/tmp/mirror', 'image/gif'));
        $result = $mirror->execute($this->authHeader(), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
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

    public function testRejectsUnauthenticatedRequestBeforeFetching(): void
    {
        $fetcher = $this->createMock(RemoteBlobFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        $store = $this->createStub(BlobStoreInterface::class);
        $mirror = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $fetcher);
        $result = $mirror->execute('', BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsBlobNotAuthorisedByHashTag(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->expects(self::once())->method('discard');

        $mirror = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'fetched'), 2048), $this->fetcher('/tmp/mirror', 'image/png'));
        $result = $mirror->execute($this->authHeader(hash('sha256', 'authorised')), BlossomFixtures::httpUrl('https://remote.test/blob'));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function useCase(BlobStoreInterface $store, BlobIndexInterface $index, BlobInspectorInterface $inspector, RemoteBlobFetcherInterface $fetcher): MirrorBlobUseCase
    {
        $ingestor = new BlobIngestor(new BlobValidator($inspector, $this->config->getUploadConstraints(), $this->authValidator), new BlobDescriptorFactory($this->config->getIdentity(), $this->clock()), $store, $index);

        return new MirrorBlobUseCase($fetcher, $this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()), $ingestor);
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

    private function authHeader(?string $hash = null): string
    {
        $tags = [
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = new Tag(TagType::sha256(), [$hash]);
        }

        $tags = new TagCollection($tags);

        $event = RumourFactory::createCustomKind(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Mirror blob'),
            $tags,
        )->sign($this->ownerKeyPair, $this->signatureService);

        return 'Nostr '.base64_encode((string) json_encode($event->toArray()));
    }
}
