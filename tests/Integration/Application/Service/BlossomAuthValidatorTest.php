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
use Innis\Nostr\Core\Domain\Service\Base64Codec;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlossomAuthValidatorTest extends TestCase
{
    private KeyPair $ownerKeyPair;
    private SignatureServiceInterface $signatureService;
    private BlossomAuthValidator $validator;
    private int $now;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $this->now = time();
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(Timestamp::fromInt($this->now));
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

        self::assertEquals(AuthenticationFailure::malformedHeader(), $result);
    }

    public function testRejectsInvalidBase64(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, 'Nostr !!!invalid!!!');

        self::assertEquals(AuthenticationFailure::invalidEncoding(), $result);
    }

    public function testAcceptsTheBud11UnpaddedBase64UrlHeader(): void
    {
        $credentials = Base64Codec::encodeUnpaddedUrl($this->signedAuthEvent($this->ownerKeyPair, 'upload')->toJson());

        self::assertInstanceOf(Event::class, $this->validator->parse(BlossomVerb::Upload, 'Nostr '.$credentials));
    }

    public function testAcceptsTheLegacyPaddedBase64Header(): void
    {
        $credentials = base64_encode($this->signedAuthEvent($this->ownerKeyPair, 'upload')->toJson());

        self::assertInstanceOf(Event::class, $this->validator->parse(BlossomVerb::Upload, 'Nostr '.$credentials));
    }

    public function testRejectsPaddedBase64UrlAsInvalidEncoding(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, 'Nostr -_8=');

        self::assertEquals(AuthenticationFailure::invalidEncoding(), $result);
    }

    public function testRejectsInvalidJson(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, BlossomFixtures::authHeaderCarrying('not json'));

        self::assertEquals(AuthenticationFailure::invalidJson(), $result);
    }

    public function testAcceptsValidEvent(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload'));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testParseAcceptsEnvelopeBeforeSignatureIsChecked(): void
    {
        $event = $this->signedAuthEvent($this->ownerKeyPair, 'upload');
        $header = BlossomFixtures::authHeaderWithInvalidSignature($event);

        self::assertInstanceOf(Event::class, $this->validator->parse(BlossomVerb::Upload, $header));
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        $event = $this->signedAuthEvent($this->ownerKeyPair, 'upload');
        $header = BlossomFixtures::authHeaderWithInvalidSignature($event);

        self::assertInstanceOf(AuthenticationFailure::class, $this->validator->verify($this->parsed(BlossomVerb::Upload, $header)));
    }

    public function testVerifyAcceptsValidSignature(): void
    {
        self::assertNull($this->validator->verify($this->parsed(BlossomVerb::Upload, $this->header('upload'))));
    }

    public function testRejectsWrongKind(): void
    {
        $event = Rumour::draft($this->ownerKeyPair->getPublicKey(), EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('hello'))
            ->sign($this->ownerKeyPair, $this->signatureService);
        $header = BlossomFixtures::authHeader($event);

        $result = $this->validator->parse(BlossomVerb::Upload, $header);

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testRejectsExpiredEvent(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', time() - 3600));

        self::assertEquals(AuthenticationFailure::expired(), $result);
    }

    public function testRejectsMissingExpirationTag(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
        ));

        self::assertEquals(AuthenticationFailure::missingExpiration(), $result);
    }

    public function testRejectsTokenWhoseSecondExpirationHasPassed(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() - 3600)]),
        ));

        self::assertEquals(AuthenticationFailure::expired(), $result);
    }

    public function testExpiryVerdictIsIndependentOfExpirationTagOrder(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() - 3600)]),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ));

        self::assertEquals(AuthenticationFailure::expired(), $result);
    }

    public function testRejectsTokenNamingMoreThanOneVerb(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::HASHTAG, 'delete']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ));

        self::assertEquals(AuthenticationFailure::ambiguousVerb(['upload', 'delete']), $result);
    }

    public function testVerbRefusalIsIndependentOfHashtagTagOrder(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'delete']),
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testAcceptsTokenRepeatingOneVerb(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testRejectsTokenWhoseVerbTagsDisagreeOnAnEmptyValue(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::HASHTAG, '']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ));

        self::assertEquals(AuthenticationFailure::ambiguousVerb(['upload', '']), $result);
    }

    public function testAcceptsTokenNamingOneVerbInTwoCases(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::HASHTAG, 'UPLOAD']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
        ));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testRejectsNegativeExpirationWithoutThrowing(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', -5));

        self::assertEquals(AuthenticationFailure::invalidExpiration(), $result);
    }

    public function testRejectsNonNumericExpiration(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, 'soon']),
        ));

        self::assertEquals(AuthenticationFailure::invalidExpiration(), $result);
    }

    public function testRejectsTokenWhoseSecondExpirationIsUnparseable(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->headerForTags(
            Tag::fromArray([TagType::HASHTAG, 'upload']),
            Tag::fromArray([TagType::EXPIRATION, (string) (time() + 3600)]),
            Tag::fromArray([TagType::EXPIRATION, 'soon']),
        ));

        self::assertEquals(AuthenticationFailure::invalidExpiration(), $result);
    }

    public function testRejectsCreatedAtTooFarInTheFuture(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: $this->now + 7200));

        self::assertEquals(AuthenticationFailure::unreasonableCreatedAt(), $result);
    }

    public function testRejectsCreatedAtTooFarInThePast(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: $this->now - (11 * 365 * 24 * 3600)));

        self::assertEquals(AuthenticationFailure::unreasonableCreatedAt(), $result);
    }

    public function testToleratesCreatedAtUpToSixtySecondsAheadOfTheServerClock(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: $this->now + 60));

        self::assertInstanceOf(Event::class, $result);
    }

    public function testRejectsCreatedAtMoreThanSixtySecondsAheadOfTheServerClock(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: $this->now + 61));

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    public function testAcceptsCreatedAtExactlyAtTheServerClock(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, $this->header('upload', createdAt: $this->now));

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
        $header = BlossomFixtures::authHeader($event);

        $result = $this->validator->parse(BlossomVerb::Upload, $header);

        self::assertInstanceOf(Event::class, $result);
    }

    public function testAuthorisesMatchingHashTag(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'blob content'));
        $event = $this->parsed(BlossomVerb::Delete, $this->header('delete', null, $hash->toHex()));

        self::assertNull($this->validator->requireAuthorisedBlob($event, $hash));
    }

    public function testDoesNotAuthoriseMismatchedHashTag(): void
    {
        $expected = BlossomFixtures::blobHash(hash('sha256', 'correct'));
        $wrong = hash('sha256', 'wrong');
        $event = $this->parsed(BlossomVerb::Delete, $this->header('delete', null, $wrong));

        self::assertInstanceOf(AuthorisationFailure::class, $this->validator->requireAuthorisedBlob($event, $expected));
    }

    public function testDoesNotAuthoriseWhenHashTagMissing(): void
    {
        $expected = BlossomFixtures::blobHash(hash('sha256', 'expected'));
        $event = $this->parsed(BlossomVerb::Delete, $this->header('delete'));

        self::assertInstanceOf(AuthorisationFailure::class, $this->validator->requireAuthorisedBlob($event, $expected));
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

        self::assertEquals(AuthorisationFailure::serverNotAuthorised(), $result);
    }

    public function testScopeAdmitsBlobWhenTokenNamesNoBlob(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'any blob'));
        $event = $this->parsed(BlossomVerb::Get, $this->header('get'));

        self::assertNull($this->validator->requireBlobInScope($event, $hash));
    }

    public function testScopeAdmitsBlobNamedByTheToken(): void
    {
        $hash = BlossomFixtures::blobHash(hash('sha256', 'scoped blob'));
        $event = $this->parsed(BlossomVerb::Get, $this->header('get', xHash: $hash->toHex()));

        self::assertNull($this->validator->requireBlobInScope($event, $hash));
    }

    public function testScopeRejectsBlobTheTokenDoesNotName(): void
    {
        $requested = BlossomFixtures::blobHash(hash('sha256', 'requested'));
        $event = $this->parsed(BlossomVerb::Get, $this->header('get', xHash: hash('sha256', 'other')));

        self::assertInstanceOf(AuthorisationFailure::class, $this->validator->requireBlobInScope($event, $requested));
    }

    public function testRejectsEmptyHeaderAsMissing(): void
    {
        $result = $this->validator->parse(BlossomVerb::Upload, '');

        self::assertInstanceOf(AuthenticationFailure::class, $result);
    }

    private function parsed(BlossomVerb $verb, string $header): Event
    {
        $result = $this->validator->parse($verb, $header);

        return $result instanceof Event ? $result : throw new InvalidArgumentException(sprintf('the authorization header does not parse: %s', $result->getMessage()));
    }

    private function headerForTags(Tag ...$tags): string
    {
        $event = Rumour::draft(
            $this->ownerKeyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
        )->sign($this->ownerKeyPair, $this->signatureService);

        return BlossomFixtures::authHeader($event);
    }

    /**
     * @param list<string> $servers
     */
    private function header(string $verb, ?int $expiration = null, ?string $xHash = null, ?int $createdAt = null, array $servers = []): string
    {
        $event = $this->signedAuthEvent($this->ownerKeyPair, $verb, $expiration, $xHash, $createdAt, $servers);

        return BlossomFixtures::authHeader($event);
    }

    /**
     * @param list<string> $servers
     */
    private function signedAuthEvent(KeyPair $keyPair, string $verb, ?int $expiration = null, ?string $xHash = null, ?int $createdAt = null, array $servers = []): Event
    {
        $tags = [
            Tag::fromArray([TagType::HASHTAG, $verb]),
            Tag::fromArray([TagType::EXPIRATION, (string) ($expiration ?? time() + 3600)]),
        ];

        if (null !== $xHash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $xHash]);
        }

        foreach ($servers as $server) {
            $tags[] = Tag::fromArray([TagType::SERVER, $server]);
        }

        return Rumour::draft(
            $keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::BLOSSOM_AUTHORISATION),
            EventContent::fromString('Blossom auth'),
            new TagCollection($tags),
            null === $createdAt ? null : Timestamp::fromInt($createdAt),
        )->sign($keyPair, $this->signatureService);
    }
}
