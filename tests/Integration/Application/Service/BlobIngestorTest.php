<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
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

final class BlobIngestorTest extends TestCase
{
    private SignatureServiceInterface $signatureService;
    private KeyPair $ownerKeyPair;
    private ServerConfig $config;
    private BlossomAuthValidator $authValidator;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $this->ownerKeyPair = KeyPair::generate($this->signatureService);
        $this->config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->ownerKeyPair->getPublicKey()],
            maxUploadBytes: 1024 * 1024,
            allowedMimeTypes: ['image/webp'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $this->clock(), $this->config->getIdentity());
    }

    public function testDiscardsTempFileWhenBlobUnreadable(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/upload');

        $this->ingestUnreadableUpload($store);
    }

    public function testDoesNotStoreUnreadableBlob(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->ingestUnreadableUpload($store);
    }

    public function testRejectsUnreadableBlob(): void
    {
        self::assertInstanceOf(BlobReadFailure::class, $this->ingestUnreadableUpload($this->createStub(BlobStoreInterface::class)));
    }

    public function testOptimiseInputIsNotGatedByStorageAllowList(): void
    {
        self::assertInstanceOf(BlobDescriptor::class, $this->optimiseDisallowedInput());
    }

    public function testOptimiseOfADisallowedInputDescribesTheOptimisedType(): void
    {
        self::assertSame('image/webp', (string) BlossomFixtures::descriptorIn($this->optimiseDisallowedInput())->getType());
    }

    public function testOptimiseOfADisallowedInputDescribesTheOptimisedHash(): void
    {
        self::assertSame(hash('sha256', 'optimised-webp'), BlossomFixtures::descriptorIn($this->optimiseDisallowedInput())->getSha256()->toHex());
    }

    public function testOptimiseInspectsInputWithoutMedia(): void
    {
        $inspector = $this->createMock(BlobInspectorInterface::class);
        $inspector->expects(self::once())->method('inspect')->with('/tmp/input')->willReturn($this->originalBlob());
        $inspector->method('inspectWithMedia')->willReturn($this->optimisedBlob());

        $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $inspector);
    }

    public function testOptimiseInspectsProducedWithMedia(): void
    {
        $inspector = $this->createMock(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($this->originalBlob());
        $inspector->expects(self::once())->method('inspectWithMedia')->with('/tmp/optimised')->willReturn($this->optimisedBlob());

        $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $inspector);
    }

    public function testDiscardsOriginalWhenOptimiserProducesNewFile(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/input');

        $this->optimiseIntoNewFile($store, $this->newFileInspector());
    }

    public function testStoresProducedFileWhenOptimiserProducesNewFile(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store')->with('/tmp/optimised', self::anything());

        $this->optimiseIntoNewFile($store, $this->newFileInspector());
    }

    public function testIngestsProducedFileWhenOptimiserProducesNewFile(): void
    {
        self::assertInstanceOf(BlobDescriptor::class, $this->optimiseIntoNewFile($this->createStub(BlobStoreInterface::class), $this->newFileInspector()));
    }

    public function testDiscardsProducedFileWhenDownstreamInspectionFailsAfterOptimisation(): void
    {
        $discarded = [];
        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('discard')->willReturnCallback(static function (string $path) use (&$discarded): void {
            $discarded[] = $path;
        });

        $this->optimiseIntoUnreadableFile($store);

        self::assertContains('/tmp/optimised', $discarded);
    }

    public function testDoesNotStoreWhenDownstreamInspectionFailsAfterOptimisation(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->optimiseIntoUnreadableFile($store);
    }

    public function testRejectsUnreadableProducedFileAfterOptimisation(): void
    {
        self::assertInstanceOf(BlobReadFailure::class, $this->optimiseIntoUnreadableFile($this->createStub(BlobStoreInterface::class)));
    }

    public function testRejectsUploadWhenAuthEventDoesNotNameTheBlob(): void
    {
        self::assertInstanceOf(AuthorisationFailure::class, $this->ingestUploadTheAuthEventDoesNotName($this->createStub(BlobStoreInterface::class)));
    }

    public function testDiscardsUploadWhenAuthEventDoesNotNameTheBlob(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/upload');

        $this->ingestUploadTheAuthEventDoesNotName($store);
    }

    public function testDoesNotStoreUploadWhenAuthEventDoesNotNameTheBlob(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');

        $this->ingestUploadTheAuthEventDoesNotName($store);
    }

    private function ingestUnreadableUpload(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willThrowException(new RuntimeException('cannot read'));

        return $this->ingestor($store, $inspector)->ingest($this->authEvent('upload'), new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')));
    }

    private function ingestUploadTheAuthEventDoesNotName(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'stored-bytes')), 4096, null, BlossomFixtures::mimeType('image/webp')));

        return $this->ingestor($store, $inspector)->ingest($this->authEvent('upload', hash('sha256', 'a-different-blob')), new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')));
    }

    private function optimiseDisallowedInput(): BlobDescriptor|BlossomFailure
    {
        $originalHash = hash('sha256', 'original-jpeg');

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096, null, BlossomFixtures::mimeType('image/jpeg')));
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'optimised-webp')), 1024, null, BlossomFixtures::mimeType('image/webp')));

        return $this->ingestor($this->createStub(BlobStoreInterface::class), $inspector)
            ->ingestOptimised($this->authEvent('media', $originalHash), new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/jpeg')), $this->optimiserProducingNewFile());
    }

    private function optimiseIntoNewFile(BlobStoreInterface $store, BlobInspectorInterface $inspector): BlobDescriptor|BlossomFailure
    {
        return $this->ingestor($store, $inspector)
            ->ingestOptimised($this->authEvent('media', hash('sha256', 'original')), new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/png')), $this->optimiserProducingNewFile());
    }

    private function optimiseIntoUnreadableFile(BlobStoreInterface $store): BlobDescriptor|BlossomFailure
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($this->originalBlob());
        $inspector->method('inspectWithMedia')->willThrowException(new RuntimeException('produced file unreadable'));

        return $this->optimiseIntoNewFile($store, $inspector);
    }

    private function optimiserProducingNewFile(): MediaOptimiserInterface
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        return $optimiser;
    }

    private function newFileInspector(): BlobInspectorInterface
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($this->originalBlob());
        $inspector->method('inspectWithMedia')->willReturn($this->optimisedBlob());

        return $inspector;
    }

    private function originalBlob(): IncomingBlob
    {
        return new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'original')), 4096);
    }

    private function optimisedBlob(): IncomingBlob
    {
        return new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'optimised')), 1024, null, BlossomFixtures::mimeType('image/webp'));
    }

    private function ingestor(BlobStoreInterface $store, BlobInspectorInterface $inspector): BlobIngestor
    {
        return new BlobIngestor(
            new BlobValidator($inspector, $this->config->getUploadConstraints(), new BlossomAccessGate($this->authValidator, $this->createStub(BlossomPolicyInterface::class))),
            new BlobDescriptorFactory($this->config->getIdentity(), $this->clock()),
            new BlobArchive($store, $this->createStub(BlobIndexInterface::class)),
        );
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());

        return $clock;
    }

    private function authEvent(string $verb, ?string $hash = null): Event
    {
        $tags = [
            Tag::fromArray([TagType::HASHTAG, $verb]),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $hash]);
        }

        return Rumour::draft(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($this->ownerKeyPair, $this->signatureService);
    }
}
