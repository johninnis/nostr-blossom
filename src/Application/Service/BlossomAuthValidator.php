<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerIdentity;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Failure\AuthHeaderDecodeFailure;
use Innis\Nostr\Core\Domain\Service\NostrAuthHeaderCodec;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Override;

final readonly class BlossomAuthValidator implements BlossomAuthValidatorInterface
{
    private const string SERVER_TAG = 'server';

    public function __construct(
        private SignatureServiceInterface $signatureService,
        private ClockInterface $clock,
        private ServerIdentity $identity,
    ) {
    }

    #[Override]
    public function parse(BlossomVerb $verb, string $authHeader): Event|BlossomFailure
    {
        $event = $this->decode($authHeader);
        if ($event instanceof BlossomFailure) {
            return $event;
        }

        return $this->verifyKind($event)
            ?? $this->verifyVerb($event, $verb)
            ?? $this->verifyServerScope($event)
            ?? $this->verifyCreatedAt($event)
            ?? $this->verifyNotExpired($event)
            ?? $event;
    }

    #[Override]
    public function verify(Event $event): ?BlossomFailure
    {
        return $event->verify($this->signatureService) ? null : AuthenticationFailure::invalidSignature();
    }

    // Deliberate: the auth event must name this blob via a matching x tag; BUD-02's SHOULD is promoted to MUST — see ADR-0006
    #[Override]
    public function requireAuthorisedBlob(Event $event, BlobHash $hash): ?BlossomFailure
    {
        return in_array($hash->toHex(), $this->namedBlobHashes($event), true) ? null : AuthorisationFailure::blobNotAuthorised();
    }

    // Deliberate: unlike ADR-0006's required binding, a get token's x tags are optional but scope it when present — see ADR-0015
    #[Override]
    public function requireBlobInScope(Event $event, BlobHash $hash): ?BlossomFailure
    {
        $named = $this->namedBlobHashes($event);

        return [] === $named || in_array($hash->toHex(), $named, true) ? null : AuthorisationFailure::blobNotAuthorised();
    }

    private function decode(string $header): Event|BlossomFailure
    {
        if ('' === $header) {
            return AuthenticationFailure::missingHeader();
        }

        $decoded = NostrAuthHeaderCodec::decode($header);
        if ($decoded instanceof Event) {
            return $decoded;
        }

        return match ($decoded) {
            AuthHeaderDecodeFailure::TooLong => AuthenticationFailure::headerTooLong(),
            AuthHeaderDecodeFailure::BadFormat => AuthenticationFailure::malformedHeader(),
            AuthHeaderDecodeFailure::BadBase64 => AuthenticationFailure::invalidEncoding(),
            AuthHeaderDecodeFailure::BadJson => AuthenticationFailure::invalidJson(),
            AuthHeaderDecodeFailure::InvalidEvent => AuthenticationFailure::invalidEvent(),
        };
    }

    private function verifyKind(Event $event): ?BlossomFailure
    {
        return $event->getKind()->is(EventKind::BLOSSOM_BLOB) ? null : AuthenticationFailure::invalidKind();
    }

    // Deliberate: server tags scope the token when present and an untagged token is valid everywhere — see ADR-0018
    private function verifyServerScope(Event $event): ?BlossomFailure
    {
        $domains = $event->getTags()->getValuesByType(TagType::fromString(self::SERVER_TAG));

        return [] === $domains || array_any($domains, $this->identity->isThisServer(...))
            ? null
            : AuthorisationFailure::serverNotAuthorised();
    }

    // Deliberate: BUD-11's created_at-in-the-past is enforced as reasonableness, tolerating client clock skew — see ADR-0016
    private function verifyCreatedAt(Event $event): ?BlossomFailure
    {
        return $event->getCreatedAt()->isReasonableAt($this->clock->now()) ? null : AuthenticationFailure::unreasonableCreatedAt();
    }

    private function verifyNotExpired(Event $event): ?BlossomFailure
    {
        $expiration = $event->getTags()->getFirstValueByType(TagType::expiration());
        if (null === $expiration) {
            return AuthenticationFailure::missingExpiration();
        }

        $expiry = Timestamp::tryFromDecimalString($expiration);
        if (null === $expiry) {
            return AuthenticationFailure::invalidExpiration();
        }

        return $this->clock->now()->isBefore($expiry) ? null : AuthenticationFailure::expired();
    }

    private function verifyVerb(Event $event, BlossomVerb $verb): ?BlossomFailure
    {
        $actual = $event->getTags()->getFirstValueByType(TagType::hashtag()) ?? '';

        return $actual === $verb->value ? null : AuthenticationFailure::wrongVerb($verb->value, $actual);
    }

    /**
     * @return list<string>
     */
    private function namedBlobHashes(Event $event): array
    {
        return $event->getTags()->getValuesByType(TagType::fromString(BlobHash::TAG));
    }
}
