<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
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
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willThrowException(new RuntimeException('cannot read'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/upload');
        $store->expects(self::never())->method('store');

        $ingestor = $this->ingestor($store, $inspector);
        $result = $ingestor->ingest($this->authEvent('upload'), new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')));

        self::assertInstanceOf(BlobReadFailure::class, $result);
    }

    public function testOptimiseInputIsNotGatedByStorageAllowList(): void
    {
        $originalHash = hash('sha256', 'original-jpeg');
        $optimisedHash = hash('sha256', 'optimised-webp');

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096, null, BlossomFixtures::mimeType('image/jpeg')));
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($optimisedHash), 1024, null, BlossomFixtures::mimeType('image/webp')));

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $ingestor = $this->ingestor($this->createStub(BlobStoreInterface::class), $inspector);
        $result = $ingestor->ingestOptimised($this->authEvent('media', $originalHash), new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/jpeg')), $optimiser);

        self::assertInstanceOf(BlobDescriptor::class, $result);
        self::assertSame('image/webp', (string) $result->getType());
        self::assertSame($optimisedHash, $result->getSha256()->toHex());
    }

    public function testOptimiseInspectsInputWithoutMediaAndProducedWithMedia(): void
    {
        $originalHash = hash('sha256', 'original');
        $optimisedHash = hash('sha256', 'optimised');

        $inspector = $this->createMock(BlobInspectorInterface::class);
        $inspector->expects(self::once())->method('inspect')->with('/tmp/input')
            ->willReturn(new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096));
        $inspector->expects(self::once())->method('inspectWithMedia')->with('/tmp/optimised')
            ->willReturn(new IncomingBlob(BlossomFixtures::blobHash($optimisedHash), 1024, null, BlossomFixtures::mimeType('image/webp')));

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $ingestor = $this->ingestor($this->createStub(BlobStoreInterface::class), $inspector);
        $result = $ingestor->ingestOptimised($this->authEvent('media', $originalHash), new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/png')), $optimiser);

        self::assertInstanceOf(BlobDescriptor::class, $result);
    }

    public function testDiscardsOriginalWhenOptimiserProducesNewFile(): void
    {
        $originalHash = hash('sha256', 'original');
        $optimisedHash = hash('sha256', 'optimised');

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096));
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($optimisedHash), 1024, null, BlossomFixtures::mimeType('image/webp')));

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/input');
        $store->expects(self::once())->method('store')->with('/tmp/optimised', self::anything());

        $ingestor = $this->ingestor($store, $inspector);
        $result = $ingestor->ingestOptimised($this->authEvent('media', $originalHash), new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/png')), $optimiser);

        self::assertInstanceOf(BlobDescriptor::class, $result);
    }

    public function testDiscardsProducedFileWhenDownstreamInspectionFailsAfterOptimisation(): void
    {
        $originalHash = hash('sha256', 'original');

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($originalHash), 4096));
        $inspector->method('inspectWithMedia')->willThrowException(new RuntimeException('produced file unreadable'));

        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturn(true);
        $optimiser->method('optimise')->willReturn(new PendingBlob('/tmp/optimised', BlossomFixtures::mimeType('image/webp')));

        $discarded = [];
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('store');
        $store->method('discard')->willReturnCallback(static function (string $path) use (&$discarded): void {
            $discarded[] = $path;
        });

        $ingestor = $this->ingestor($store, $inspector);
        $result = $ingestor->ingestOptimised($this->authEvent('media', $originalHash), new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/png')), $optimiser);

        self::assertInstanceOf(BlobReadFailure::class, $result);
        self::assertContains('/tmp/optimised', $discarded);
    }

    public function testRejectsUploadWhenAuthEventDoesNotNameTheBlob(): void
    {
        $storedHash = hash('sha256', 'stored-bytes');
        $namedHash = hash('sha256', 'a-different-blob');

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willReturn(new IncomingBlob(BlossomFixtures::blobHash($storedHash), 4096, null, BlossomFixtures::mimeType('image/webp')));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/upload');
        $store->expects(self::never())->method('store');

        $ingestor = $this->ingestor($store, $inspector);
        $result = $ingestor->ingest($this->authEvent('upload', $namedHash), new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function ingestor(BlobStoreInterface $store, BlobInspectorInterface $inspector): BlobIngestor
    {
        return new BlobIngestor(
            new BlobValidator($inspector, $this->config->getUploadConstraints(), $this->authValidator),
            new BlobDescriptorFactory($this->config->getIdentity(), $this->clock()),
            $store,
            $this->createStub(BlobIndexInterface::class),
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
            new Tag(TagType::hashtag(), [$verb]),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ];

        if (null !== $hash) {
            $tags[] = new Tag(TagType::fromString(BlobHash::TAG), [$hash]);
        }

        return RumourFactory::createCustomKind(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($this->ownerKeyPair, $this->signatureService);
    }
}
