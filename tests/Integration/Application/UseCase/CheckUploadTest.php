<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\CheckUploadUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\DeclaredBlob;
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

final class CheckUploadTest extends TestCase
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
            maxUploadBytes: 1024,
            allowedMimeTypes: ['image/png', 'image/jpeg'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $this->clock(), $this->config->getIdentity());
    }

    public function testAcceptsWellFormedDeclaredUpload(): void
    {
        $hash = hash('sha256', 'declared content');
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertNull($result);
    }

    public function testRejectsMissingAuthHeader(): void
    {
        $hash = hash('sha256', 'declared content');

        $result = $this->useCase()->execute('', new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsWrongVerb(): void
    {
        $hash = hash('sha256', 'declared content');
        $authHeader = $this->buildAuthHeader('delete', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'declared content');
        $declared = new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png'));

        $trusted = $this->useCase()->execute($this->headerWithInvalidSignature($this->ownerKeyPair, 'upload', $hash), $declared);
        $unknown = $this->useCase()->execute($this->headerWithInvalidSignature($stranger, 'upload', $hash), $declared);

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'declared content');
        $declared = new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png'));

        $result = $this->useCase()->execute($this->headerWithInvalidSignature($stranger, 'upload', $hash), $declared);

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'declared content');
        $declared = new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png'));

        $result = $this->useCase()->execute($this->buildAuthHeader('upload', $hash, $stranger), $declared);

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testDeclaredGuardsRunBeforeSignatureIsVerified(): void
    {
        $hash = hash('sha256', 'declared content');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'upload', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 4096, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(BlobTooLargeFailure::class, $result);
    }

    public function testRejectsInvalidSignatureWhenDeclaredValuesPass(): void
    {
        $hash = hash('sha256', 'declared content');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'upload', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsDeclaredSizeOverLimit(): void
    {
        $hash = hash('sha256', 'declared content');
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 4096, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(BlobTooLargeFailure::class, $result);
    }

    public function testRejectsDisallowedDeclaredType(): void
    {
        $hash = hash('sha256', 'declared content');
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('application/pdf')));

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testRejectsWhenDeclaredHashIsNotNamedByAuth(): void
    {
        $namedHash = hash('sha256', 'the authorised blob');
        $declaredHash = hash('sha256', 'a different blob');
        $authHeader = $this->buildAuthHeader('upload', $namedHash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($declaredHash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function useCase(): CheckUploadUseCase
    {
        return new CheckUploadUseCase(new BlossomAccessGate($this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys())), $this->config->getUploadConstraints());
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());

        return $clock;
    }

    private function buildAuthHeader(string $verb, string $hash, ?KeyPair $signer = null): string
    {
        $signer ??= $this->ownerKeyPair;

        $event = Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection([
                Tag::fromArray([TagType::HASHTAG, $verb]),
                Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
                Tag::fromArray([TagType::SHA256, $hash]),
            ]),
        )->sign($signer, $this->signatureService);

        return BlossomFixtures::authHeader($event);
    }

    private function headerWithInvalidSignature(KeyPair $signer, string $verb, string $hash): string
    {
        $event = Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection([
                Tag::fromArray([TagType::HASHTAG, $verb]),
                Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
                Tag::fromArray([TagType::SHA256, $hash]),
            ]),
        )->sign($signer, $this->signatureService);

        return BlossomFixtures::authHeaderWithInvalidSignature($event);
    }
}
