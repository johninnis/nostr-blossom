<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\OptimiseMediaUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\MediaOptimisationFailure;
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

    public function testSuccessfulOptimiseStoresTheOutput(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');

        $this->optimiseIntoNewFile($store, $this->createStub(BlobIndexInterface::class), $this->newFileInspector());
    }

    public function testSuccessfulOptimiseDiscardsTheOriginal(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->optimiseIntoNewFile($store, $this->createStub(BlobIndexInterface::class), $this->newFileInspector());
    }

    public function testSuccessfulOptimiseIndexesTheOutput(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::once())->method('save');

        $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $index, $this->newFileInspector());
    }

    public function testSuccessfulOptimiseDescribesTheOutputHash(): void
    {
        self::assertSame(hash('sha256', 'optimised'), BlossomFixtures::descriptorIn($this->optimisedNewFile())->getSha256()->toHex());
    }

    public function testSuccessfulOptimiseDescribesTheOutputSize(): void
    {
        self::assertSame(1024, BlossomFixtures::descriptorIn($this->optimisedNewFile())->getSize());
    }

    public function testSuccessfulOptimiseDescribesTheOutputType(): void
    {
        self::assertSame('image/webp', (string) BlossomFixtures::descriptorIn($this->optimisedNewFile())->getType());
    }

    public function testSuccessfulOptimiseAddressesTheOutputByItsHash(): void
    {
        self::assertStringEndsWith('/'.hash('sha256', 'optimised').'.webp', (string) BlossomFixtures::descriptorIn($this->optimisedNewFile())->getUrl());
    }

    public function testSuccessfulOptimiseCarriesTheOutputHashInItsNip94(): void
    {
        self::assertSame(hash('sha256', 'optimised'), BlossomFixtures::descriptorIn($this->optimisedNewFile())->getNip94()?->getHash());
    }

    public function testSuccessfulOptimiseCarriesTheOriginalHashInItsNip94(): void
    {
        self::assertSame(hash('sha256', 'original'), BlossomFixtures::descriptorIn($this->optimisedNewFile())->getNip94()?->getOriginalHash());
    }

    // Deliberate: pins ADR-0012 — the discarded original is inspected without a media decode, only the stored output decodes media
    public function testInspectsOriginalWithoutMedia(): void
    {
        $inspector = $this->createMock(BlobInspectorInterface::class);
        $inspector->expects(self::once())->method('inspect')->with('/tmp/input')->willReturn($this->originalBlob());
        $inspector->method('inspectWithMedia')->willReturn($this->optimisedBlob());

        $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $inspector);
    }

    // Deliberate: pins ADR-0012 — the discarded original is inspected without a media decode, only the stored output decodes media
    public function testInspectsOutputWithMedia(): void
    {
        $inspector = $this->createMock(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($this->originalBlob());
        $inspector->expects(self::once())->method('inspectWithMedia')->with('/tmp/optimised')->willReturn($this->optimisedBlob());

        $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $inspector);
    }

    public function testInPlaceOptimiserKeepsProducedFile(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('discard');

        $this->optimiseInPlace($store);
    }

    public function testInPlaceOptimiserStoresProducedFile(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store');

        $this->optimiseInPlace($store);
    }

    public function testInPlaceOptimiserDescribesProducedFileByItsHash(): void
    {
        self::assertSame(hash('sha256', 'optimised'), BlossomFixtures::descriptorIn($this->optimiseInPlace($this->createStub(BlobStoreInterface::class)))->getSha256()->toHex());
    }

    public function testRejectsUnsupportedType(): void
    {
        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $this->optimiseUnsupportedType($this->createStub(BlobStoreInterface::class)));
    }

    public function testDiscardsInputOfUnsupportedType(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->optimiseUnsupportedType($store);
    }

    public function testDoesNotStoreInputOfUnsupportedType(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseUnsupportedType($store);
    }

    public function testRejectsBlobNotAuthorisedByHashTag(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->optimiseBlobTheTokenDoesNotName($this->createStub(BlobStoreInterface::class)));
    }

    public function testDiscardsBlobNotAuthorisedByHashTag(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->optimiseBlobTheTokenDoesNotName($store);
    }

    public function testDoesNotStoreBlobNotAuthorisedByHashTag(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseBlobTheTokenDoesNotName($store);
    }

    public function testRejectsWhenOriginalIsUnreadable(): void
    {
        self::assertInstanceOf(BlobReadFailure::class, $this->optimiseUnreadableOriginal($this->createStub(BlobStoreInterface::class)));
    }

    public function testDiscardsOriginalWhenItIsUnreadable(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->optimiseUnreadableOriginal($store);
    }

    public function testDoesNotStoreWhenOriginalIsUnreadable(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseUnreadableOriginal($store);
    }

    public function testRejectsWhenOptimiserFails(): void
    {
        self::assertInstanceOf(MediaOptimisationFailure::class, $this->optimiseWithFailingOptimiser($this->createStub(BlobStoreInterface::class)));
    }

    public function testDiscardsOriginalWhenOptimiserFails(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard');

        $this->optimiseWithFailingOptimiser($store);
    }

    public function testDoesNotStoreWhenOptimiserFails(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseWithFailingOptimiser($store);
    }

    public function testRejectsOptimiserOutputWithDisallowedType(): void
    {
        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $this->optimiseIntoDisallowedType($this->createStub(BlobStoreInterface::class)));
    }

    public function testDoesNotStoreOptimiserOutputWithDisallowedType(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseIntoDisallowedType($store);
    }

    public function testRejectsOptimiserOutputExceedingMaxSize(): void
    {
        self::assertInstanceOf(BlobTooLargeFailure::class, $this->optimiseIntoOversizedOutput($this->createStub(BlobStoreInterface::class)));
    }

    public function testDoesNotStoreOptimiserOutputExceedingMaxSize(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseIntoOversizedOutput($store);
    }

    public function testRejectsMissingAuth(): void
    {
        $result = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->createStub(MediaOptimiserInterface::class))
            ->execute('', $this->createStub(PendingBlobSourceInterface::class));

        self::assertInstanceOf(BlossomFailure::class, $result);
    }

    public function testDoesNotStageBodyWhenAuthIsMissing(): void
    {
        $source = $this->createMock(PendingBlobSourceInterface::class);
        $source->expects(self::never())->method('stage');

        $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->createStub(MediaOptimiserInterface::class))
            ->execute('', $source);
    }

    public function testReturnsTheSourceFailure(): void
    {
        $tooLarge = BlobTooLargeFailure::beyondMaximum(1024);

        $result = $this->optimiseFailingSource($tooLarge, $this->createStub(BlobStoreInterface::class), $this->createStub(MediaOptimiserInterface::class));

        self::assertSame($tooLarge, $result);
    }

    public function testDoesNotOptimiseWhenTheSourceFails(): void
    {
        $optimiser = $this->createMock(MediaOptimiserInterface::class);
        $optimiser->expects(self::never())->method('optimise');

        $this->optimiseFailingSource(BlobTooLargeFailure::beyondMaximum(1024), $this->createStub(BlobStoreInterface::class), $optimiser);
    }

    public function testDoesNotStoreWhenTheSourceFails(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseFailingSource(BlobTooLargeFailure::beyondMaximum(1024), $store, $this->createStub(MediaOptimiserInterface::class));
    }

    public function testDoesNotDiscardWhenTheSourceFails(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('discard');

        $this->optimiseFailingSource(BlobTooLargeFailure::beyondMaximum(1024), $store, $this->createStub(MediaOptimiserInterface::class));
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'original');
        $optimise = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->createStub(MediaOptimiserInterface::class));

        $trusted = $optimise->execute($this->invalidlySignedHeader($hash), $this->source('/tmp/input', 'image/png'));
        $unknown = $optimise->execute($this->invalidlySignedHeader($hash, $stranger), $this->source('/tmp/input', 'image/png'));

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'original');
        $optimise = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->createStub(MediaOptimiserInterface::class));

        $result = $optimise->execute($this->invalidlySignedHeader($hash, $stranger), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'original');
        $optimise = $this->useCase($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $this->createStub(MediaOptimiserInterface::class));

        $result = $optimise->execute($this->authHeader($hash, $stranger), $this->source('/tmp/input', 'image/png'));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function optimisedNewFile(): BlobDescriptor|BlossomFailure
    {
        return $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class), $this->newFileInspector());
    }

    private function optimiseIntoNewFile(BlobStoreInterface $store, BlobIndexInterface $index, BlobInspectorInterface $inspector): BlobDescriptor|BlossomFailure
    {
        return $this->useCase($store, $index, $inspector, $this->optimiserProducing('/tmp/optimised', 'image/webp'))
            ->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseInPlace(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->newFileInspector(), $this->optimiserProducing('/tmp/input', 'image/png'))
            ->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseUnsupportedType(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(false);

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $optimiser)
            ->execute($this->authHeader(), $this->source('/tmp/input', 'application/pdf'));
    }

    private function optimiseBlobTheTokenDoesNotName(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($this->originalBlob());

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $this->optimiserSupportingEveryType())
            ->execute($this->authHeader(hash('sha256', 'a different blob')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseUnreadableOriginal(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willThrowException(new RuntimeException('cannot read original'));

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $this->optimiserSupportingEveryType())
            ->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseWithFailingOptimiser(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($this->originalBlob());

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willThrowException(new RuntimeException('encoder crashed'));

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $optimiser)
            ->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseIntoDisallowedType(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->newFileInspector(), $this->optimiserProducing('/tmp/optimised', 'image/gif'))
            ->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseIntoOversizedOutput(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->inspector($this->originalBlob(), new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'optimised')), 2 * 1024 * 1024));

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $inspector, $this->optimiserProducing('/tmp/optimised', 'image/webp'))
            ->execute($this->authHeader(hash('sha256', 'original')), $this->source('/tmp/input', 'image/png'));
    }

    private function optimiseFailingSource(BlossomFailure $sourceFailure, BlobStoreInterface $store, MediaOptimiserInterface $optimiser): BlobDescriptor|BlossomFailure
    {
        $source = $this->createStub(PendingBlobSourceInterface::class);
        $source->method('stage')->willReturn($sourceFailure);

        return $this->useCase($store, $this->createStub(BlobIndexInterface::class), $this->createStub(BlobInspectorInterface::class), $optimiser)
            ->execute($this->authHeader(), $source);
    }

    private function optimiserProducing(string $path, string $mimeType): MediaOptimiserInterface
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob($path, BlossomFixtures::mimeType($mimeType)));

        return $optimiser;
    }

    private function optimiserSupportingEveryType(): MediaOptimiserInterface
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);

        return $optimiser;
    }

    private function newFileInspector(): BlobInspectorInterface
    {
        return $this->inspector($this->originalBlob(), $this->optimisedBlob());
    }

    private function originalBlob(): IncomingBlob
    {
        return new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'original')), 4096);
    }

    private function optimisedBlob(): IncomingBlob
    {
        return new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'optimised')), 1024);
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
        $gate = new BlossomAccessGate($this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys()));
        $ingestor = new BlobIngestor(new BlobValidator($inspector, $this->config->getUploadConstraints(), $gate), new BlobDescriptorFactory($this->config->getIdentity(), $this->clock()), new BlobArchive($store, $index));

        return new OptimiseMediaUseCase($optimiser, $gate, $ingestor);
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
            Tag::fromArray([TagType::HASHTAG, 'media']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $hash]);
        }

        return Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Optimise media'),
            new TagCollection($tags),
        )->sign($signer, $this->signatureService);
    }
}
