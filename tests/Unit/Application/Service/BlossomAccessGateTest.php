<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Service\BlossomAccessGate;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlossomAccessGateTest extends TestCase
{
    private const string HEADER = 'Nostr token';

    /**
     * @var list<string>
     */
    private array $calls = [];

    private Event $token;
    private BlobHash $hash;

    protected function setUp(): void
    {
        $this->token = $this->authEvent();
        $this->hash = BlossomFixtures::blobHash(hash('sha256', 'blob'));
    }

    public function testUploadVerifiesTheSignatureBeforeAskingThePolicy(): void
    {
        $this->gate()->authoriseUpload(self::HEADER);

        self::assertSame(['parse:upload', 'verify', 'allowUpload'], $this->calls);
    }

    public function testUploadAdmitsTheVerifiedToken(): void
    {
        self::assertSame($this->token, $this->gate()->authoriseUpload(self::HEADER));
    }

    public function testUploadNeverAsksThePolicyAboutAnInvalidSignature(): void
    {
        $this->gate(['verify' => AuthenticationFailure::invalidSignature()])->authoriseUpload(self::HEADER);

        self::assertSame(['parse:upload', 'verify'], $this->calls);
    }

    public function testUploadReturnsTheInvalidSignatureRefusal(): void
    {
        $invalid = AuthenticationFailure::invalidSignature();

        self::assertSame($invalid, $this->gate(['verify' => $invalid])->authoriseUpload(self::HEADER));
    }

    public function testUploadStopsAtAnUnparsableHeaderBeforeVerifying(): void
    {
        $this->gate(['parse' => AuthenticationFailure::malformedHeader()])->authoriseUpload(self::HEADER);

        self::assertSame(['parse:upload'], $this->calls);
    }

    public function testUploadReturnsTheUnparsableHeaderRefusal(): void
    {
        $malformed = AuthenticationFailure::malformedHeader();

        self::assertSame($malformed, $this->gate(['parse' => $malformed])->authoriseUpload(self::HEADER));
    }

    public function testUploadReturnsThePolicyRefusal(): void
    {
        $refused = AuthorisationFailure::notTenant();

        self::assertSame($refused, $this->gate(['policy' => $refused])->authoriseUpload(self::HEADER));
    }

    public function testMediaVerifiesTheSignatureBeforeAskingTheMediaPolicy(): void
    {
        $this->gate()->authoriseMedia(self::HEADER);

        self::assertSame(['parse:media', 'verify', 'allowMedia'], $this->calls);
    }

    public function testMediaAdmitsTheVerifiedToken(): void
    {
        self::assertSame($this->token, $this->gate()->authoriseMedia(self::HEADER));
    }

    public function testUploadCheckBindsTheDeclaredBlobBeforeVerifying(): void
    {
        $this->gate()->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): null => null);

        self::assertSame(['parse:upload', 'requireAuthorisedBlob', 'verify', 'allowUpload'], $this->calls);
    }

    public function testUploadCheckPassesAnAuthorisedDeclaredBlob(): void
    {
        self::assertNull($this->gate()->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): null => null));
    }

    public function testUploadCheckRefusesADeclaredValueBeforeTheBindingOrSignature(): void
    {
        $this->gate()->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): BlossomFailure => BlobTooLargeFailure::beyondMaximum(1024));

        self::assertSame(['parse:upload'], $this->calls);
    }

    public function testUploadCheckReturnsTheDeclaredValueRefusal(): void
    {
        $tooLarge = BlobTooLargeFailure::beyondMaximum(1024);

        self::assertSame($tooLarge, $this->gate()->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): BlossomFailure => $tooLarge));
    }

    public function testUploadCheckReportsAnUnparsableHeaderAheadOfADeclaredRefusal(): void
    {
        $malformed = AuthenticationFailure::malformedHeader();

        $result = $this->gate(['parse' => $malformed])->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): BlossomFailure => BlobTooLargeFailure::beyondMaximum(1024));

        self::assertSame($malformed, $result);
    }

    public function testMediaCheckNeverRunsTheDeclaredChecksForAnUnparsableHeader(): void
    {
        $gate = $this->gate(['parse' => AuthenticationFailure::malformedHeader()]);

        $gate->authoriseMediaCheck(self::HEADER, $this->hash, function (): null {
            $this->calls[] = 'declaredChecks';

            return null;
        });

        self::assertSame(['parse:media'], $this->calls);
    }

    public function testUploadCheckRefusesAnUnboundBlobBeforeVerifying(): void
    {
        $this->gate(['requireAuthorisedBlob' => AuthorisationFailure::blobNotAuthorised()])->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): null => null);

        self::assertSame(['parse:upload', 'requireAuthorisedBlob'], $this->calls);
    }

    public function testUploadCheckReturnsTheUnboundBlobRefusal(): void
    {
        $unbound = AuthorisationFailure::blobNotAuthorised();

        self::assertSame($unbound, $this->gate(['requireAuthorisedBlob' => $unbound])->authoriseUploadCheck(self::HEADER, $this->hash, static fn (): null => null));
    }

    public function testMediaCheckBindsTheDeclaredBlobBeforeVerifyingAndAsksTheMediaPolicy(): void
    {
        $this->gate()->authoriseMediaCheck(self::HEADER, $this->hash, static fn (): null => null);

        self::assertSame(['parse:media', 'requireAuthorisedBlob', 'verify', 'allowMedia'], $this->calls);
    }

    public function testMediaCheckPassesAnAuthorisedDeclaredBlob(): void
    {
        self::assertNull($this->gate()->authoriseMediaCheck(self::HEADER, $this->hash, static fn (): null => null));
    }

    public function testDeleteBindsTheBlobBeforeVerifyingAndVerifiesBeforeThePolicy(): void
    {
        $this->gate()->authoriseDelete(self::HEADER, $this->hash);

        self::assertSame(['parse:delete', 'requireAuthorisedBlob', 'verify', 'allowDelete'], $this->calls);
    }

    public function testDeleteAdmitsTheVerifiedToken(): void
    {
        self::assertSame($this->token, $this->gate()->authoriseDelete(self::HEADER, $this->hash));
    }

    public function testDeleteRefusesAnUnboundBlobWithoutVerifying(): void
    {
        $this->gate(['requireAuthorisedBlob' => AuthorisationFailure::blobNotAuthorised()])->authoriseDelete(self::HEADER, $this->hash);

        self::assertSame(['parse:delete', 'requireAuthorisedBlob'], $this->calls);
    }

    public function testDeleteReturnsTheUnboundBlobRefusal(): void
    {
        $unbound = AuthorisationFailure::blobNotAuthorised();

        self::assertSame($unbound, $this->gate(['requireAuthorisedBlob' => $unbound])->authoriseDelete(self::HEADER, $this->hash));
    }

    public function testListVerifiesBeforeAskingThePolicyAboutTheTarget(): void
    {
        $this->gate()->authoriseList(self::HEADER, BlossomFixtures::publicKey(str_repeat('d', 64)));

        self::assertSame(['parse:list', 'verify', 'allowList:'.str_repeat('d', 64)], $this->calls);
    }

    public function testListPassesATargetThePolicyAllows(): void
    {
        self::assertNull($this->gate()->authoriseList(self::HEADER, BlossomFixtures::publicKey(str_repeat('d', 64))));
    }

    public function testListReturnsThePolicyRefusal(): void
    {
        $refused = AuthorisationFailure::mayOnlyListOwnBlobs();

        self::assertSame($refused, $this->gate(['policy' => $refused])->authoriseList(self::HEADER, BlossomFixtures::publicKey(str_repeat('d', 64))));
    }

    public function testPublicGetNeverParsesAPresentHeader(): void
    {
        $this->gate()->authoriseGet(self::HEADER, $this->hash);

        self::assertSame(['allowGet:anonymous'], $this->calls);
    }

    public function testPublicGetPassesWhateverHeaderIsPresent(): void
    {
        self::assertNull($this->gate()->authoriseGet(self::HEADER, $this->hash));
    }

    public function testGatedGetWithoutAHeaderReturnsTheAnonymousRefusal(): void
    {
        $anonymousRefusal = AuthenticationFailure::missingHeader();

        self::assertSame($anonymousRefusal, $this->gate(['anonymousGet' => $anonymousRefusal])->authoriseGet('', $this->hash));
    }

    public function testGatedGetWithoutAHeaderAsksOnlyTheAnonymousPolicy(): void
    {
        $this->gate(['anonymousGet' => AuthenticationFailure::missingHeader()])->authoriseGet('', $this->hash);

        self::assertSame(['allowGet:anonymous'], $this->calls);
    }

    public function testGatedGetScopesThenVerifiesThenAsksThePolicyAboutTheActor(): void
    {
        $this->gate(['anonymousGet' => AuthenticationFailure::missingHeader()])->authoriseGet(self::HEADER, $this->hash);

        self::assertSame(['allowGet:anonymous', 'parse:get', 'requireBlobInScope', 'verify', 'allowGet:actor'], $this->calls);
    }

    public function testGatedGetPassesAVerifiedActorThePolicyAllows(): void
    {
        self::assertNull($this->gate(['anonymousGet' => AuthenticationFailure::missingHeader()])->authoriseGet(self::HEADER, $this->hash));
    }

    public function testGatedGetRefusesAnOutOfScopeTokenBeforeVerifying(): void
    {
        $this->gate(['anonymousGet' => AuthenticationFailure::missingHeader(), 'requireBlobInScope' => AuthorisationFailure::blobNotAuthorised()])->authoriseGet(self::HEADER, $this->hash);

        self::assertSame(['allowGet:anonymous', 'parse:get', 'requireBlobInScope'], $this->calls);
    }

    public function testGatedGetReturnsTheOutOfScopeRefusal(): void
    {
        $outOfScope = AuthorisationFailure::blobNotAuthorised();

        self::assertSame($outOfScope, $this->gate(['anonymousGet' => AuthenticationFailure::missingHeader(), 'requireBlobInScope' => $outOfScope])->authoriseGet(self::HEADER, $this->hash));
    }

    public function testGatedGetNeverAsksThePolicyAboutAnInvalidSignature(): void
    {
        $this->gate(['anonymousGet' => AuthenticationFailure::missingHeader(), 'verify' => AuthenticationFailure::invalidSignature()])->authoriseGet(self::HEADER, $this->hash);

        self::assertSame(['allowGet:anonymous', 'parse:get', 'requireBlobInScope', 'verify'], $this->calls);
    }

    public function testGatedGetReturnsTheInvalidSignatureRefusal(): void
    {
        $invalid = AuthenticationFailure::invalidSignature();

        self::assertSame($invalid, $this->gate(['anonymousGet' => AuthenticationFailure::missingHeader(), 'verify' => $invalid])->authoriseGet(self::HEADER, $this->hash));
    }

    public function testReceivedBlobIsBoundWithoutReparsingVerifyingOrAskingThePolicy(): void
    {
        $this->gate(['requireAuthorisedBlob' => AuthorisationFailure::blobNotAuthorised()])->authoriseReceivedBlob($this->token, $this->hash);

        self::assertSame(['requireAuthorisedBlob'], $this->calls);
    }

    public function testReceivedBlobReturnsTheUnboundBlobRefusal(): void
    {
        $unbound = AuthorisationFailure::blobNotAuthorised();

        self::assertSame($unbound, $this->gate(['requireAuthorisedBlob' => $unbound])->authoriseReceivedBlob($this->token, $this->hash));
    }

    /**
     * @param array<string, BlossomFailure> $refusals keyed by the call that refuses
     */
    private function gate(array $refusals = []): BlossomAccessGate
    {
        $answer = function (string $call, string $refusedBy) use ($refusals): ?BlossomFailure {
            $this->calls[] = $call;

            return $refusals[$refusedBy] ?? null;
        };

        $validator = $this->createStub(BlossomAuthValidatorInterface::class);
        $validator->method('parse')->willReturnCallback(fn (BlossomVerb $verb): Event|BlossomFailure => $answer('parse:'.$verb->value, 'parse') ?? $this->token);
        $validator->method('requireAuthorisedBlob')->willReturnCallback(static fn (): ?BlossomFailure => $answer('requireAuthorisedBlob', 'requireAuthorisedBlob'));
        $validator->method('requireBlobInScope')->willReturnCallback(static fn (): ?BlossomFailure => $answer('requireBlobInScope', 'requireBlobInScope'));
        $validator->method('verify')->willReturnCallback(static fn (): ?BlossomFailure => $answer('verify', 'verify'));

        $policy = $this->createStub(BlossomPolicyInterface::class);
        $policy->method('allowUpload')->willReturnCallback(static fn (): ?BlossomFailure => $answer('allowUpload', 'policy'));
        $policy->method('allowMedia')->willReturnCallback(static fn (): ?BlossomFailure => $answer('allowMedia', 'policy'));
        $policy->method('allowDelete')->willReturnCallback(static fn (): ?BlossomFailure => $answer('allowDelete', 'policy'));
        $policy->method('allowList')->willReturnCallback(static fn (PublicKey $actor, PublicKey $target): ?BlossomFailure => $answer('allowList:'.$target->toHex(), 'policy'));
        $policy->method('allowGet')->willReturnCallback(static fn (?PublicKey $actor): ?BlossomFailure => null === $actor
            ? $answer('allowGet:anonymous', 'anonymousGet')
            : $answer('allowGet:actor', 'policy'));

        return new BlossomAccessGate($validator, $policy);
    }

    private function authEvent(): Event
    {
        $json = (string) json_encode([
            'id' => str_repeat('a', 64),
            'pubkey' => str_repeat('b', 64),
            'created_at' => 1_000_000,
            'kind' => EventKind::BLOSSOM_AUTHORISATION,
            'tags' => [['t', 'upload']],
            'content' => '',
            'sig' => str_repeat('c', 128),
        ]);

        return Event::tryFromJson($json) ?? throw new RuntimeException('invalid auth event fixture');
    }
}
