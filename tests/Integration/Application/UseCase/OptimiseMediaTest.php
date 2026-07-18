<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\OptimiseMediaUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\MediaOptimisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
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
use RuntimeException;

final class OptimiseMediaTest extends TestCase
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
            allowedMimeTypes: ['image/png', 'image/webp'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $this->clock(), $this->config->getIdentity());
    }

    public function testSuccessfulOptimise(): void
    {
        $originalHash = hash('sha256', 'original');
        $optimisedHash = hash('sha256', 'optimised');

        $inspector = $this->inspector(
            new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096),
            new IncomingBlob(BlossomFixtures::blobHash($optimisedHash), 1024),
        );

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');
        $store->expects(self::once())->method('discard');

        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::once())->method('save');

        $optimise = $this->useCase($store, $index, $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader($originalHash), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame($optimisedHash, $result->getSha256()->toHex());
        self::assertSame(1024, $result->getSize());
        self::assertSame('image/webp', (string) $result->getType());
        self::assertStringEndsWith('/'.$optimisedHash.'.webp', (string) $result->getUrl());
        $nip94 = $result->getNip94();
        self::assertInstanceOf(FileMetadata::class, $nip94);
        self::assertSame($optimisedHash, $nip94->getHash());
        self::assertSame($originalHash, $nip94->getOriginalHash());
    }

    // Deliberate: pins ADR-0012 — the discarded original is inspected without a media decode, only the stored output decodes media
    public function testInspectsOriginalWithoutMediaAndOutputWithMedia(): void
    {
        $originalHash = hash('sha256', 'original');
        $optimisedHash = hash('sha256', 'optimised');

        $inspector = $this->createMock(BlobInspectorInterface::class);
        $inspector->expects(self::once())->method('inspect')->with('/tmp/input')
            ->willReturn(new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096));
        $inspector->expects(self::once())->method('inspectWithMedia')->with('/tmp/optimised')
            ->willReturn(new IncomingBlob(BlossomFixtures::blobHash($optimisedHash), 1024));

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $optimise = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader($originalHash), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(BlobDescriptor::class, $result);
    }

    public function testInPlaceOptimiserKeepsProducedFile(): void
    {
        $originalHash = hash('sha256', 'original');
        $optimisedHash = hash('sha256', 'optimised');

        $inspector = $this->inspector(
            new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096),
            new IncomingBlob(BlossomFixtures::blobHash($optimisedHash), 1024),
        );

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/png')));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');
        $store->expects(self::never())->method('discard');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader($originalHash), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame($optimisedHash, $result->getSha256()->toHex());
    }

    public function testRejectsUnsupportedType(): void
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(false);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');
        $store->expects(self::never())->method('store');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $optimiser);
        $result = $optimise->execute($this->authHeader(), $this->source('/tmp/input', 'application/pdf'));

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testRejectsBlobNotAuthorisedByHashTag(): void
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn(
            new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'original')), 4096),
        );

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');
        $store->expects(self::never())->method('store');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader(hash('sha256', 'a different blob')), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsWhenOriginalIsUnreadable(): void
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willThrowException(new RuntimeException('cannot read original'));

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');
        $store->expects(self::never())->method('store');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(BlobReadFailure::class, $result);
    }

    public function testRejectsWhenOptimiserFails(): void
    {
        $originalHash = hash('sha256', 'original');

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn(
            new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096),
        );

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willThrowException(new RuntimeException('encoder crashed'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');
        $store->expects(self::never())->method('store');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader($originalHash), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(MediaOptimisationFailure::class, $result);
    }

    public function testRejectsOptimiserOutputWithDisallowedType(): void
    {
        $originalHash = hash('sha256', 'original');

        $inspector = $this->inspector(
            new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096),
            new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'optimised')), 1024),
        );

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/gif')));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader($originalHash), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testRejectsOptimiserOutputExceedingMaxSize(): void
    {
        $originalHash = hash('sha256', 'original');

        $inspector = $this->inspector(
            new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096),
            new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'optimised')), 2 * 1024 * 1024),
        );

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $optimise = $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser);
        $result = $optimise->execute($this->authHeader($originalHash), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(BlobTooLargeFailure::class, $result);
    }

    public function testDoesNotStageBodyWhenAuthIsMissing(): void
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimise = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $optimiser);

        $source = $this->createMock(PendingBlobSourceInterface::class);
        $source->expects(self::never())->method('stage');

        $result = $optimise->execute('', $source);

        self::assertInstanceOf(BlossomFailure::class, $result);
    }

    private function inspector(IncomingBlob $input, IncomingBlob $produced): BlobInspectorInterface
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($input);
        $inspector->method('inspectWithMedia')->willReturn($produced);

        return $inspector;
    }

    private function useCase(BlobStoreInterface $store, BlobIndexInterface $index, BlobInspectorInterface $inspector, MediaOptimiserInterface $optimiser): OptimiseMediaUseCase
    {
        $ingestor = new BlobIngestor(new BlobValidator($inspector, $this->config->getUploadConstraints(), $this->authValidator), new BlobDescriptorFactory($this->config->getIdentity(), $this->clock()), $store, $index);

        return new OptimiseMediaUseCase($optimiser, $this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()), $ingestor);
    }

    private function source(string $path, string $contentType): PendingBlobSourceInterface
    {
        $source = $this->createStub(PendingBlobSourceInterface::class);
        $source->method('stage')->willReturn(new PendingBlob($path, BlossomFixtures::mimeType($contentType)));

        return $source;
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
            new Tag(TagType::hashtag(), ['media']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = new Tag(TagType::fromString(BlobHash::TAG), [$hash]);
        }

        $event = RumourFactory::createCustomKind(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Optimise media'),
            new TagCollection($tags),
        )->sign($this->ownerKeyPair, $this->signatureService);

        return 'Nostr '.base64_encode((string) json_encode($event->toArray()));
    }
}
