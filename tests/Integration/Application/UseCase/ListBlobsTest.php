<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\ListBlobsUseCase;
use Innis\Nostr\Blossom\Domain\Collection\BlobDescriptorCollection;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
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

final class ListBlobsTest extends TestCase
{
    private ServerConfig $config;
    private KeyPair $ownerKeyPair;
    private SignatureServiceInterface $signatureService;
    private BlossomAccessGate $gate;

    protected function setUp(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());
        $this->signatureService = Secp256k1Signer::create();
        $this->ownerKeyPair = KeyPair::generate($this->signatureService);
        $this->config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->ownerKeyPair->getPublicKey()],
            maxUploadBytes: 1024 * 1024,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
        $this->gate = new BlossomAccessGate(
            new BlossomAuthValidator($this->signatureService, $clock, $this->config->getIdentity()),
            new TenantBlossomPolicy($this->config->getTenantPubkeys()),
        );
    }

    public function testReturnsOwnBlobsForAuthenticatedTenant(): void
    {
        $sha256 = hash('sha256', 'blob1');
        $descriptor = new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256.'.png'),
            sha256: BlossomFixtures::blobHash($sha256),
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('list')->willReturn(new BlobDescriptorCollection([$descriptor]));

        $listBlobs = new ListBlobsUseCase($index, $this->gate);
        $result = $listBlobs->execute($this->authHeader(), $this->ownerKeyPair->getPublicKey(), new ListQuery());

        self::assertInstanceOf(BlobDescriptorCollection::class, $result);
        self::assertCount(1, $result);
        self::assertSame($descriptor, $result->toArray()[0]);
    }

    public function testRejectsListingAnotherTenantsBlobs(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('list');

        $listBlobs = new ListBlobsUseCase($index, $this->gate);
        $result = $listBlobs->execute($this->authHeader(), BlossomFixtures::publicKey(str_repeat('cd', 32)), new ListQuery());

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $listBlobs = new ListBlobsUseCase($this->createStub(BlobIndexInterface::class), $this->gate);

        $trusted = $listBlobs->execute($this->invalidlySignedHeader($this->ownerKeyPair), $this->ownerKeyPair->getPublicKey(), new ListQuery());
        $unknown = $listBlobs->execute($this->invalidlySignedHeader($stranger), $stranger->getPublicKey(), new ListQuery());

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $listBlobs = new ListBlobsUseCase($this->createStub(BlobIndexInterface::class), $this->gate);

        $result = $listBlobs->execute($this->invalidlySignedHeader($stranger), $stranger->getPublicKey(), new ListQuery());

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $listBlobs = new ListBlobsUseCase($this->createStub(BlobIndexInterface::class), $this->gate);

        $result = $listBlobs->execute(BlossomFixtures::authHeader($this->listAuthEvent($stranger)), $stranger->getPublicKey(), new ListQuery());

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsMissingAuth(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('list');

        $listBlobs = new ListBlobsUseCase($index, $this->gate);
        $result = $listBlobs->execute('', $this->ownerKeyPair->getPublicKey(), new ListQuery());

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    private function authHeader(): string
    {
        return BlossomFixtures::authHeader($this->listAuthEvent($this->ownerKeyPair));
    }

    private function invalidlySignedHeader(KeyPair $keyPair): string
    {
        return BlossomFixtures::authHeaderWithInvalidSignature($this->listAuthEvent($keyPair));
    }

    private function listAuthEvent(KeyPair $keyPair): Event
    {
        $tags = new TagCollection([
            Tag::fromArray([TagType::HASHTAG, 'list']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ]);

        return Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('List blobs'),
            $tags,
        )->sign($keyPair, $this->signatureService);
    }
}
