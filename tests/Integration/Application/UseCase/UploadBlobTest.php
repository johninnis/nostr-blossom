<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\UploadBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
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

    public function testSuccessfulUploadStoresTheBlob(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');

        $this->uploadAuthorisedBlob($store, $this->createStub(BlobIndexInterface::class));
    }

    public function testSuccessfulUploadIndexesTheBlob(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::once())->method('save');

        $this->uploadAuthorisedBlob($this->createStub(BlobStoreInterface::class), $index);
    }

    public function testSuccessfulUploadDescribesTheBlobByItsHash(): void
    {
        $result = $this->uploadAuthorisedBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class));

        self::assertSame(hash('sha256', 'test image data'), BlossomFixtures::descriptorIn($result)->getSha256()->toHex());
    }

    public function testUploadCarriesNoNip94WhenInspectorReportsNoMedia(): void
    {
        $result = $this->uploadAuthorisedBlob($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class));

        self::assertNull(BlossomFixtures::descriptorIn($result)->getNip94());
    }

    public function testUploadCarriesTheInspectedDimensionsInItsNip94(): void
    {
        self::assertSame('640x480', BlossomFixtures::descriptorIn($this->uploadWithMedia())->getNip94()?->getDimensions());
    }

    public function testUploadCarriesTheInspectedBlurhashInItsNip94(): void
    {
        self::assertSame('LEHV6nWB2yk8', BlossomFixtures::descriptorIn($this->uploadWithMedia())->getNip94()?->getBlurhash());
    }

    public function testUploadCarriesTheBlobHashInItsNip94(): void
    {
        self::assertSame(hash('sha256', 'test image data'), BlossomFixtures::descriptorIn($this->uploadWithMedia())->getNip94()?->getHash());
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
        self::assertSame('image/jpeg', (string) BlossomFixtures::descriptorIn($this->uploadDeclaredAsPng('actually a jpeg', 'image/jpeg'))->getType());
    }

    public function testDetectedMimeTypeDecidesTheUrlExtension(): void
    {
        self::assertStringEndsWith('.jpg', (string) BlossomFixtures::descriptorIn($this->uploadDeclaredAsPng('actually a jpeg', 'image/jpeg'))->getUrl());
    }

    public function testFallsBackToDeclaredTypeWhenDetectionIsGeneric(): void
    {
        self::assertSame('image/png', (string) BlossomFixtures::descriptorIn($this->uploadDeclaredAsPng('opaque bytes', 'application/octet-stream'))->getType());
    }

    public function testFallsBackToDeclaredExtensionWhenDetectionIsGeneric(): void
    {
        self::assertStringEndsWith('.png', (string) BlossomFixtures::descriptorIn($this->uploadDeclaredAsPng('opaque bytes', 'application/octet-stream'))->getUrl());
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
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ]);

        $event = Rumour::draft(
            $otherKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Upload'),
            $tags,
        )->sign($otherKeyPair, $this->signatureService);

        $authHeader = BlossomFixtures::authHeader($event);

        $store = $this->createStub(BlobStoreInterface::class);
        $upload = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', $content), strlen($content)), $this->config);
        $result = $upload->execute($authHeader, $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(BlossomFailure::class, $result);
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $upload = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'data'), 4), $this->config);

        $trusted = $upload->execute($this->headerWithInvalidSignature($this->ownerKeyPair, 'upload'), $this->source('/tmp/upload', 'image/png'), null);
        $unknown = $upload->execute($this->headerWithInvalidSignature($stranger, 'upload'), $this->source('/tmp/upload', 'image/png'), null);

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $upload = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'data'), 4), $this->config);

        $result = $upload->execute($this->headerWithInvalidSignature($stranger, 'upload'), $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $upload = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->inspector(hash('sha256', 'data'), 4), $this->config);

        $result = $upload->execute($this->buildAuthHeader('upload', null, $stranger), $this->source('/tmp/upload', 'image/png'), null);

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsMissingAuth(): void
    {
        $upload = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->config);

        self::assertInstanceOf(BlossomFailure::class, $upload->execute('', $this->createStub(PendingBlobSourceInterface::class), null));
    }

    public function testDoesNotStageBodyWhenAuthIsMissing(): void
    {
        $source = $this->createMock(PendingBlobSourceInterface::class);
        $source->expects(self::never())->method('stage');

        $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->config)
            ->execute('', $source, null);
    }

    public function testReturnsTheSourceFailure(): void
    {
        $tooLarge = BlobTooLargeFailure::beyondMaximum(1024);

        self::assertSame($tooLarge, $this->uploadFromFailingSource($tooLarge, $this->createStub(BlobStoreInterface::class)));
    }

    public function testDoesNotStoreWhenTheSourceFails(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->uploadFromFailingSource(BlobTooLargeFailure::beyondMaximum(1024), $store);
    }

    public function testDoesNotDiscardWhenTheSourceFails(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('discard');

        $this->uploadFromFailingSource(BlobTooLargeFailure::beyondMaximum(1024), $store);
    }

    private function uploadAuthorisedBlob(BlobStoreInterface $store, BlobIndexInterface $index): BlobDescriptor|BlossomFailure
    {
        $content = 'test image data';
        $hash = hash('sha256', $content);

        return $this->useCase($store, $index, $this->inspector($hash, strlen($content)), $this->config)
            ->execute($this->buildAuthHeader('upload', $hash), $this->source('/tmp/upload', 'image/png'), null);
    }

    private function uploadWithMedia(): BlobDescriptor|BlossomFailure
    {
        $content = 'test image data';
        $hash = hash('sha256', $content);
        $inspector = $this->inspector($hash, strlen($content), new MediaMetadata(dimensions: '640x480', blurhash: 'LEHV6nWB2yk8'));

        return $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $inspector, $this->config)
            ->execute($this->buildAuthHeader('upload', $hash), $this->source('/tmp/upload', 'image/png'), null);
    }

    private function uploadDeclaredAsPng(string $content, string $detectedMimeType): BlobDescriptor|BlossomFailure
    {
        $hash = hash('sha256', $content);
        $inspector = $this->inspector($hash, strlen($content), null, $detectedMimeType);

        return $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $inspector, $this->config)
            ->execute($this->buildAuthHeader('upload', $hash), $this->source('/tmp/upload', 'image/png'), null);
    }

    private function uploadFromFailingSource(BlossomFailure $sourceFailure, BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $source = $this->createStub(PendingBlobSourceInterface::class);
        $source->method('stage')->willReturn($sourceFailure);

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->config)
            ->execute($this->buildAuthHeader('upload'), $source, null);
    }

    private function useCase(BlobStoreInterface $store, BlobIndexInterface $index, BlobInspectorInterface $inspector, ServerConfig $config): UploadBlobUseCase
    {
        $gate = new BlossomAccessGate($this->authValidator, new TenantBlossomPolicy($config->getTenantPubkeys()));
        $ingestor = new BlobIngestor(new BlobValidator($inspector, $config->getUploadConstraints(), $gate), new BlobDescriptorFactory($config->getIdentity(), $this->clock()), new BlobArchive($store, $index));

        return new UploadBlobUseCase($gate, $ingestor);
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

    private function buildAuthHeader(string $verb, ?string $hash = null, ?KeyPair $signer = null): string
    {
        $signer ??= $this->ownerKeyPair;

        $tags = [
            Tag::fromArray([TagType::HASHTAG, $verb]),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $hash]);
        }

        $event = Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($signer, $this->signatureService);

        return BlossomFixtures::authHeader($event);
    }

    private function headerWithInvalidSignature(KeyPair $signer, string $verb): string
    {
        $event = Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection([
                Tag::fromArray([TagType::HASHTAG, $verb]),
                Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
            ]),
        )->sign($signer, $this->signatureService);

        return BlossomFixtures::authHeaderWithInvalidSignature($event);
    }
}
