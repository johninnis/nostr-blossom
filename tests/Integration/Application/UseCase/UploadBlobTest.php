<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\UploadBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobIntegrityFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MediaMetadata;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Content\FileMetadata;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use PHPUnit\Framework\TestCase;

final class UploadBlobTest extends TestCase
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
            allowedMimeTypes: ['image/png', 'image/jpeg'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $this->clock(), $this->config->getIdentity());
    }

    public function testSuccessfulUpload(): void
    {
        $content = 'test image data';
        $hash = hash('sha256', $content);
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::once())->method('save');

        $upload = $this->useCase($store, $index, $this->inspector($hash, strlen($content)), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame($hash, $result->getSha256()->toHex());
        self::assertNull($result->getNip94());
    }

    public function testUploadCarriesNip94WhenInspectorReportsMedia(): void
    {
        $content = 'test image data';
        $hash = hash('sha256', $content);
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $media = new MediaMetadata(dimensions: '640x480', blurhash: 'LEHV6nWB2yk8');
        $store = $this->createStub(BlobStoreInterface::class);
        $index = $this->createStub(BlobIndexInterface::class);

        $upload = $this->useCase($store, $index, $this->inspector($hash, strlen($content), $media), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlobDescriptor::class, $result);
        $nip94 = $result->getNip94();
        self::assertInstanceOf(FileMetadata::class, $nip94);
        self::assertSame('640x480', $nip94->getDimensions());
        self::assertSame('LEHV6nWB2yk8', $nip94->getBlurhash());
        self::assertSame($hash, $nip94->getHash());
    }

    public function testRejectsUnsupportedMimeType(): void
    {
        $authHeader = $this->buildAuthHeader('upload');

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'data'), 4), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'application/pdf'), null);

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testDetectedMimeTypeOverridesDeclaredType(): void
    {
        $content = 'actually a jpeg';
        $hash = hash('sha256', $content);
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $store = $this->createStub(BlobStoreInterface::class);
        $index = $this->createStub(BlobIndexInterface::class);
        $upload = $this->useCase($store, $index, $this->inspector($hash, strlen($content), null, 'image/jpeg'), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame('image/jpeg', (string) $result->getType());
        self::assertStringEndsWith('.jpg', (string) $result->getUrl());
    }

    public function testFallsBackToDeclaredTypeWhenDetectionIsGeneric(): void
    {
        $content = 'opaque bytes';
        $hash = hash('sha256', $content);
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $store = $this->createStub(BlobStoreInterface::class);
        $index = $this->createStub(BlobIndexInterface::class);
        $upload = $this->useCase($store, $index, $this->inspector($hash, strlen($content), null, 'application/octet-stream'), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame('image/png', (string) $result->getType());
        self::assertStringEndsWith('.png', (string) $result->getUrl());
    }

    public function testRejectsWhenDetectedTypeIsDisallowedDespiteAllowedDeclaration(): void
    {
        $content = 'a pdf masquerading as png';
        $hash = hash('sha256', $content);
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector($hash, strlen($content), null, 'application/pdf'), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testRejectsFileTooLarge(): void
    {
        $config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->ownerKeyPair->getPublicKey()],
            maxUploadBytes: 5,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
        $authHeader = $this->buildAuthHeader('upload');

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'too large content'), 17), $config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlobTooLargeFailure::class, $result);
    }

    public function testRejectsMismatchedDeclaredHash(): void
    {
        $authHeader = $this->buildAuthHeader('upload');

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'real content'), 12), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), BlossomFixtures::blobHash(hash('sha256', 'different')));

        self::assertInstanceOf(BlobIntegrityFailure::class, $result);
    }

    public function testRejectsNonOwnerUpload(): void
    {
        $otherKeyPair = KeyPair::generate($this->signatureService);
        $content = 'data';

        $tags = new TagCollection([
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ]);

        $event = RumourFactory::createCustomKind(
            $otherKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Upload'),
            $tags,
        )->sign($otherKeyPair, $this->signatureService);

        $authHeader = 'Nostr '.base64_encode((string) json_encode($event->toArray()));

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', $content), strlen($content)), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlossomFailure::class, $result);
    }

    public function testAdmissionRejectsUnauthorisedUploaderBeforeSignatureIsVerified(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $authHeader = $this->headerWithInvalidSignature($stranger, 'upload');

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'data'), 4), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testDoesNotStageBodyWhenAuthIsMissing(): void
    {
        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->config);

        $source = $this->createMock(PendingBlobSourceInterface::class);
        $source->expects(self::never())->method('stage');

        $result = $upload->execute('', $source, null);

        self::assertInstanceOf(BlossomFailure::class, $result);
    }

    public function testReturnsTheSourceFailureWithoutIngesting(): void
    {
        $tooLarge = BlobTooLargeFailure::beyondMaximum(1024);
        $source = $this->createStub(PendingBlobSourceInterface::class);
        $source->method('stage')->willReturn($tooLarge);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->expects(self::never())->method('discard');

        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->config);
        $result = $upload->execute($this->buildAuthHeader('upload'), $source, null);

        self::assertSame($tooLarge, $result);
    }

    private function useCase(BlobStoreInterface $store, BlobIndexInterface $index, BlobInspectorInterface $inspector, ServerConfig $config): UploadBlobUseCase
    {
        $ingestor = new BlobIngestor(new BlobValidator($inspector, $config->getUploadConstraints(), $this->authValidator), new BlobDescriptorFactory($config->getIdentity(), $this->clock()), $store, $index);

        return new UploadBlobUseCase($this->authValidator, new TenantBlossomPolicy($config->getTenantPubkeys()), $ingestor);
    }

    private function source(string $path, string $contentType): PendingBlobSourceInterface
    {
        $source = $this->createStub(PendingBlobSourceInterface::class);
        $source->method('stage')->willReturn(new PendingBlob($path, BlossomFixtures::mimeType($contentType)));

        return $source;
    }

    private function inspector(string $hash, int $size, ?MediaMetadata $media = null, ?string $detectedMimeType = null): BlobInspectorInterface
    {
        $detected = null === $detectedMimeType ? null : BlossomFixtures::mimeType($detectedMimeType);
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($hash), $size, $media, $detected));

        return $inspector;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());

        return $clock;
    }

    private function buildAuthHeader(string $verb, ?string $hash = null): string
    {
        $tags = [
            new Tag(TagType::hashtag(), [$verb]),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = new Tag(TagType::sha256(), [$hash]);
        }

        $event = RumourFactory::createCustomKind(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($this->ownerKeyPair, $this->signatureService);

        return 'Nostr '.base64_encode((string) json_encode($event->toArray()));
    }

    private function headerWithInvalidSignature(KeyPair $signer, string $verb): string
    {
        $event = RumourFactory::createCustomKind(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection([
                new Tag(TagType::hashtag(), [$verb]),
                new Tag(TagType::expiration(), [(string) (time() + 3600)]),
            ]),
        )->sign($signer, $this->signatureService);

        $array = $event->toArray();
        $array['sig'] = str_repeat('0', 128);

        return 'Nostr '.base64_encode((string) json_encode($array));
    }
}
