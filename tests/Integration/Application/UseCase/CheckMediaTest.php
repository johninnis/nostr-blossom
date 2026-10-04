<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\MediaOptimiserInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\CheckMediaUseCase;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\DeclaredBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
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

final class CheckMediaTest extends TestCase
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
            allowedMimeTypes: ['image/webp'],
            baseUrl: 'https://blossom.test',
        );
        $this->authValidator = new BlossomAuthValidator($this->signatureService, $this->clock(), $this->config->getIdentity());
    }

    public function testAcceptsWellFormedDeclaredMedia(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->buildAuthHeader('media', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertNull($result);
    }

    public function testDeclaredTypeIsJudgedByOptimiserSupportNotTheStorageAllowList(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->buildAuthHeader('media', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/jpeg')));

        self::assertNull($result);
    }

    public function testRejectsDeclaredTypeTheOptimiserDoesNotSupport(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->buildAuthHeader('media', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('application/pdf')));

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testRejectsDeclaredSizeOverLimit(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->buildAuthHeader('media', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 4096, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(BlobTooLargeFailure::class, $result);
    }

    public function testRejectsWhenDeclaredHashIsNotNamedByAuth(): void
    {
        $namedHash = hash('sha256', 'the authorised blob');
        $declaredHash = hash('sha256', 'a different blob');
        $authHeader = $this->buildAuthHeader('media', $namedHash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($declaredHash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testRejectsWrongVerb(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->buildAuthHeader('upload', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsInvalidSignatureWhenDeclaredValuesPass(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'media', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png')));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testDeclaredGuardsRunBeforeSignatureIsVerified(): void
    {
        $hash = hash('sha256', 'declared media');
        $authHeader = $this->headerWithInvalidSignature($this->ownerKeyPair, 'media', $hash);

        $result = $this->useCase()->execute($authHeader, new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('application/pdf')));

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testInvalidSignatureGetsTheSameRefusalWhetherTheKeyIsTrustedOrUnknown(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'declared media');
        $declared = new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png'));

        $trusted = $this->useCase()->execute($this->headerWithInvalidSignature($this->ownerKeyPair, 'media', $hash), $declared);
        $unknown = $this->useCase()->execute($this->headerWithInvalidSignature($stranger, 'media', $hash), $declared);

        self::assertEquals($trusted, $unknown);
    }

    public function testInvalidSignatureFromAnUnknownKeyIsRefusedAsUnauthenticated(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'declared media');
        $declared = new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png'));

        $result = $this->useCase()->execute($this->headerWithInvalidSignature($stranger, 'media', $hash), $declared);

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testValidlySignedUnknownKeyIsRefusedByPolicy(): void
    {
        $stranger = KeyPair::generate($this->signatureService);
        $hash = hash('sha256', 'declared media');
        $declared = new DeclaredBlob(BlossomFixtures::blobHash($hash), 512, BlossomFixtures::mimeType('image/png'));

        $result = $this->useCase()->execute(BlossomFixtures::authHeader($this->signedAuthEvent($stranger, 'media', $hash)), $declared);

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    private function useCase(): CheckMediaUseCase
    {
        return new CheckMediaUseCase($this->optimiser(), new BlossomAccessGate($this->authValidator, new TenantBlossomPolicy($this->config->getTenantPubkeys())), $this->config->getUploadConstraints());
    }

    private function optimiser(): MediaOptimiserInterface
    {
        $optimiser = $this->createStub(MediaOptimiserInterface::class);
        $optimiser->method('supports')->willReturnCallback(
            static fn (MimeType $mimeType): bool => str_starts_with((string) $mimeType, 'image/'),
        );

        return $optimiser;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());

        return $clock;
    }

    private function buildAuthHeader(string $verb, string $hash): string
    {
        return BlossomFixtures::authHeader($this->signedAuthEvent($this->ownerKeyPair, $verb, $hash));
    }

    private function headerWithInvalidSignature(KeyPair $signer, string $verb, string $hash): string
    {
        return BlossomFixtures::authHeaderWithInvalidSignature($this->signedAuthEvent($signer, $verb, $hash));
    }

    private function signedAuthEvent(KeyPair $signer, string $verb, string $hash): Event
    {
        return Rumour::draft(
            $signer->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection([
                Tag::fromArray([TagType::HASHTAG, $verb]),
                Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
                Tag::fromArray([TagType::SHA256, $hash]),
            ]),
        )->sign($signer, $this->signatureService);
    }
}
