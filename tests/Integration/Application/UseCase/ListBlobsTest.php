<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
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

final class ListBlobsTest extends TestCase
{
    private ServerConfig $config;
    private KeyPair $ownerKeyPair;
    private SignatureServiceInterface $signatureService;
    private BlossomAuthValidator $authValidator;
    private TenantBlossomPolicy $policy;

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
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $clock, $this->config->getIdentity());
        $this->policy = new TenantBlossomPolicy($this->config->getTenantPubkeys());
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

        $listBlobs = new ListBlobsUseCase($index, $this->authValidator, $this->policy);
        $result = $listBlobs->execute($this->authHeader(), $this->ownerKeyPair->getPublicKey(), new ListQuery());

        self::assertInstanceOf(BlobDescriptorCollection::class, $result);
        self::assertCount(1, $result);
        self::assertSame($descriptor, $result->toArray()[0]);
    }

    public function testRejectsListingAnotherTenantsBlobs(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('list');

        $listBlobs = new ListBlobsUseCase($index, $this->authValidator, $this->policy);
        $result = $listBlobs->execute($this->authHeader(), BlossomFixtures::publicKey(str_repeat('cd', 32)), new ListQuery());

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testAdmissionRejectsNonTenantBeforeSignatureIsVerified(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('list');

        $listBlobs = new ListBlobsUseCase($index, $this->authValidator, $this->policy);
        $result = $listBlobs->execute($this->invalidlySignedHeader($stranger), $stranger->getPublicKey(), new ListQuery());

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsMissingAuth(): void
    {
        $index = $this->createMock(BlobIndexInterface::class);
        $index->expects(self::never())->method('list');

        $listBlobs = new ListBlobsUseCase($index, $this->authValidator, $this->policy);
        $result = $listBlobs->execute('', $this->ownerKeyPair->getPublicKey(), new ListQuery());

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    private function authHeader(): string
    {
        return 'Nostr '.base64_encode((string) json_encode($this->listAuthEvent($this->ownerKeyPair)->toArray()));
    }

    private function invalidlySignedHeader(KeyPair $keyPair): string
    {
        $data = $this->listAuthEvent($keyPair)->toArray();
        $data['sig'] = str_repeat('0', 128);

        return 'Nostr '.base64_encode((string) json_encode($data));
    }

    private function listAuthEvent(KeyPair $keyPair): Event
    {
        $tags = new TagCollection([
            new Tag(TagType::hashtag(), ['list']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ]);

        return RumourFactory::createCustomKind(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('List blobs'),
            $tags,
        )->sign($keyPair, $this->signatureService);
    }
}
