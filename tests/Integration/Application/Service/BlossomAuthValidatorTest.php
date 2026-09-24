<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\Service;

use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
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

final class BlossomAuthValidatorTest extends TestCase
{
    private KeyPair $ownerKeyPair;
    private SignatureServiceInterface $signatureService;
    private BlossomAuthValidator $validator;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::now());
        $this->ownerKeyPair = KeyPair::generate($this->signatureService);
        $config = BlossomFixtures::serverConfig(
            tenantPubkeys: [$this->ownerKeyPair->getPublicKey()],
            maxUploadBytes: 1024 * 1024,
            allowedMimeTypes: ['image/png'],
            baseUrl: 'https://blossom.test',
        );
        $this->validator = new BlossomAuthValidator($this->signatureService, $clock, $config->getIdentity());
    }

    public function testRejectsMissingNostrPrefix(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, 'Bearer abc123');

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Malformed Nostr authorization', $result->getMessage());
    }

    public function testRejectsInvalidBase64(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, 'Nostr !!!invalid!!!');

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization header is not valid base64', $result->getMessage());
    }

    public function testRejectsInvalidJson(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, 'Nostr '.base64_encode('not json'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization header is not valid JSON', $result->getMessage());
    }

    public function testAcceptsValidEvent(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload'));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testParseAcceptsEnvelopeBeforeSignatureIsChecked(): void
    {
        $event = $this->signedAuthEvent($this->ownerKeyPair, 'upload');
        $data = $event->toArray();
        $data['sig'] = str_repeat('0', 128);
        $header = 'Nostr '.base64_encode((string) json_encode($data));

        self::assertInstanceOf(Event::class, $this->validator->parse(BlossomVerb::Upload, $header));
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        $event = $this->signedAuthEvent($this->ownerKeyPair, 'upload');
        $data = $event->toArray();
        $data['sig'] = str_repeat('0', 128);
        $header = 'Nostr '.base64_encode((string) json_encode($data));

        $parsed = $this->validator->parse(BlossomVerb::Upload, $header);
        self::assertInstanceOf(Event::class, $parsed);
        self::assertInstanceOf(AuthenticationFailure::class, $this->validator->verify($parsed));
    }

    public function testVerifyAcceptsValidSignature(): void
    {
        $parsed = $this->validator->parse(BlossomVerb::Upload, $this->header('upload'));

        self::assertInstanceOf(Event::class, $parsed);
        self::assertNull($this->validator->verify($parsed));
    }

    public function testRejectsWrongKind(): void
    {
        $event = RumourFactory::createTextNote($this->ownerKeyPair->getPublicKey(), 'hello')
            ->sign($this->ownerKeyPair, $this->signatureService);
        $header = 'Nostr '.base64_encode((string) json_encode($event->toArray()));

        $result = $this->validator->parse(BlossomVerb::Upload, $header);

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsExpiredEvent(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', time() - 3600));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event has expired', $result->getMessage());
    }

    public function testRejectsMissingExpirationTag(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event is missing an expiration tag', $result->getMessage());
    }

    public function testRejectsTokenWhoseSecondExpirationHasPassed(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
            new Tag(TagType::expiration(), [(string) (time() - 3600)]),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event has expired', $result->getMessage());
    }

    public function testExpiryVerdictIsIndependentOfExpirationTagOrder(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() - 3600)]),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event has expired', $result->getMessage());
    }

    public function testRejectsTokenNamingMoreThanOneVerb(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::hashtag(), ['delete']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event must name exactly one verb, got "upload", "delete"', $result->getMessage());
    }

    public function testVerbRefusalIsIndependentOfHashtagTagOrder(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['delete']),
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testAcceptsTokenRepeatingOneVerb(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        ));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testRejectsNegativeExpirationWithoutThrowing(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', -5));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event has an invalid expiration tag', $result->getMessage());
    }

    public function testRejectsNonNumericExpiration(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), ['soon']),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event has an invalid expiration tag', $result->getMessage());
    }

    public function testRejectsTokenWhoseSecondExpirationIsUnparseable(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            new Tag(TagType::hashtag(), ['upload']),
            new Tag(TagType::expiration(), [(string) (time() + 3600)]),
            new Tag(TagType::expiration(), ['soon']),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event has an invalid expiration tag', $result->getMessage());
    }

    public function testRejectsCreatedAtTooFarInTheFuture(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: time() + 7200));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event created_at is too far from the current time', $result->getMessage());
    }

    public function testRejectsCreatedAtTooFarInThePast(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: time() - (11 * 365 * 24 * 3600)));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
        self::assertSame('Authorization event created_at is too far from the current time', $result->getMessage());
    }

    public function testToleratesCreatedAtSlightlyInTheFutureForClockSkew(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: time() + 30));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testRejectsWrongVerb(): void
    {
        $result = $this->validator->parse(BlossomVerb::Delete, $this->header('upload'));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testAcceptsAnyPubkeyLeavingTenancyToPolicy(): void
    {
        $other = KeyPair::generate($this->signatureService);
        $event = $this->signedAuthEvent($other, 'upload');
        $header = 'Nostr '.base64_encode((string) json_encode($event->toArray()));

        $result = $this->validator->parse(BlossomVerb::Upload, $header);

        self::assertInstanceOf(Event::class, $result);
    }

    public function testAuthorisesMatchingHashTag(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'blob content'));
        $result = $this->validator->parse(BlossomVerb::Delete, $this->header('delete', null, $hash->toHex()));

        self::assertInstanceOf(Event::class, $result);
        self::assertNull($this->validator->requireAuthorisedBlob($result, $hash));
    }

    public function testDoesNotAuthoriseMismatchedHashTag(): void
    {
        $expected = BlossomFixtures::blobHash(hash('sha256', 'correct'));
        $wrong = hash('sha256', 'wrong');
        $result = $this->validator->parse(BlossomVerb::Delete, $this->header('delete', null, $wrong));

        self::assertInstanceOf(Event::class, $result);
        self::assertInstanceOf(AuthorisationFailure::class, $this->validator->requireAuthorisedBlob($result, $expected));
    }

    public function testDoesNotAuthoriseWhenHashTagMissing(): void
    {
        $expected = BlossomFixtures::blobHash(hash('sha256', 'expected'));
        $result = $this->validator->parse(BlossomVerb::Delete, $this->header('delete'));

        self::assertInstanceOf(Event::class, $result);
        self::assertInstanceOf(AuthorisationFailure::class, $this->validator->requireAuthorisedBlob($result, $expected));
    }

    public function testAcceptsTokenScopedToThisServer(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', servers: ['blossom.test']));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testAcceptsTokenNamingThisServerAmongOthers(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', servers: ['other.example', 'blossom.test']));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testServerScopeComparisonIsCaseInsensitive(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', servers: ['BLOSSOM.TEST']));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testRejectsTokenScopedToAnotherServer(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', servers: ['other.example']));

        self::assertInstanceOf(AuthorisationFailure::class, $result);
        self::assertSame('Authorization event does not authorise this server', $result->getMessage());
    }

    public function testScopeAdmitsBlobWhenTokenNamesNoBlob(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'any blob'));
        $result = $this->validator->parse(BlossomVerb::Get, $this->header('get'));

        self::assertInstanceOf(Event::class, $result);
        self::assertNull($this->validator->requireBlobInScope($result, $hash));
    }

    public function testScopeAdmitsBlobNamedByTheToken(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'scoped blob'));
        $result = $this->validator->parse(BlossomVerb::Get, $this->header('get', xHash: $hash->toHex()));

        self::assertInstanceOf(Event::class, $result);
        self::assertNull($this->validator->requireBlobInScope($result, $hash));
    }

    public function testScopeRejectsBlobTheTokenDoesNotName(): void
    {
        $requested = BlossomFixtures::blobHash(hash('sha256', 'requested'));
        $result = $this->validator->parse(BlossomVerb::Get, $this->header('get', xHash: hash('sha256', 'other')));

        self::assertInstanceOf(Event::class, $result);
        self::assertInstanceOf(AuthorisationFailure::class, $this->validator->requireBlobInScope($result, $requested));
    }

    public function testRejectsEmptyHeaderAsMissing(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, '');

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    private function headerForTags(Tag ...$tags): string
    {
        $event = RumourFactory::createCustomKind(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($this->ownerKeyPair, $this->signatureService);

        return 'Nostr '.base64_encode((string) json_encode($event->toArray()));
    }

    /**
     * @param list<string> $servers
     */
    private function header(string $verb, ?int $expiration = null, ?string $xHash = null, ?int $createdAt = null, array $servers = []): string
    {
        $event = $this->signedAuthEvent($this->ownerKeyPair, $verb, $expiration, $xHash, $createdAt, $servers);

        return 'Nostr '.base64_encode((string) json_encode($event->toArray()));
    }

    /**
     * @param list<string> $servers
     */
    private function signedAuthEvent(KeyPair $keyPair, string $verb, ?int $expiration = null, ?string $xHash = null, ?int $createdAt = null, array $servers = []): Event
    {
        $tags = [
            new Tag(TagType::hashtag(), [$verb]),
            new Tag(TagType::expiration(), [(string) ($expiration ?? time() + 3600)]),
        ];

        if (null !== $xHash) {
            $tags[] = new Tag(TagType::sha256(), [$xHash]);
        }

        foreach ($servers as $server) {
            $tags[] = new Tag(TagType::server(), [$server]);
        }

        return RumourFactory::createCustomKind(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_BLOB),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
            null === $createdAt ? null : Timestamp::fromInt($createdAt),
        )->sign($keyPair, $this->signatureService);
    }
}
