<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Closure;
use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class BlossomAccessGate
{
    public function __construct(
        private BlossomAuthValidatorInterface $validator,
        private BlossomPolicyInterface $policy,
    ) {
    }

    public function authoriseUpload(string $authHeader): Event|BlossomFailure
    {
        return $this->authorise(
            $this->validator->parse(BlossomVerb::Upload, $authHeader),
            static fn (): null => null,
            $this->policy->allowUpload(...),
        );
    }

    public function authoriseMedia(string $authHeader): Event|BlossomFailure
    {
        return $this->authorise(
            $this->validator->parse(BlossomVerb::Media, $authHeader),
            static fn (): null => null,
            $this->policy->allowMedia(...),
        );
    }

    /**
     * @param Closure(): ?BlossomFailure $declaredChecks
     */
    public function authoriseUploadCheck(string $authHeader, BlobHash $hash, Closure $declaredChecks): ?BlossomFailure
    {
        return $this->refusalOf($this->authorise(
            $this->validator->parse(BlossomVerb::Upload, $authHeader),
            fn (Event $auth): ?BlossomFailure => $declaredChecks() ?? $this->validator->requireAuthorisedBlob($auth, $hash),
            $this->policy->allowUpload(...),
        ));
    }

    /**
     * @param Closure(): ?BlossomFailure $declaredChecks
     */
    public function authoriseMediaCheck(string $authHeader, BlobHash $hash, Closure $declaredChecks): ?BlossomFailure
    {
        return $this->refusalOf($this->authorise(
            $this->validator->parse(BlossomVerb::Media, $authHeader),
            fn (Event $auth): ?BlossomFailure => $declaredChecks() ?? $this->validator->requireAuthorisedBlob($auth, $hash),
            $this->policy->allowMedia(...),
        ));
    }

    public function authoriseDelete(string $authHeader, BlobHash $hash): Event|BlossomFailure
    {
        return $this->authorise(
            $this->validator->parse(BlossomVerb::Delete, $authHeader),
            fn (Event $auth): ?BlossomFailure => $this->validator->requireAuthorisedBlob($auth, $hash),
            $this->policy->allowDelete(...),
        );
    }

    public function authoriseList(string $authHeader, PublicKey $target): ?BlossomFailure
    {
        return $this->refusalOf($this->authorise(
            $this->validator->parse(BlossomVerb::List, $authHeader),
            static fn (): null => null,
            fn (PublicKey $actor): ?BlossomFailure => $this->policy->allowList($actor, $target),
        ));
    }

    // Deliberate: the anonymous allowGet(null, …) is asked first, so a read the policy opens to everyone never parses or verifies a header, valid or not — see ADR-0026
    public function authoriseGet(string $authHeader, BlobHash $hash): ?BlossomFailure
    {
        $anonymous = $this->policy->allowGet(null, $hash);
        if (null === $anonymous || '' === $authHeader) {
            return $anonymous;
        }

        // Deliberate: the cheap x-tag scope check runs before verify (ADR-0015), and verify runs before the policy check because that check may read private per-blob ownership (ADR-0014)
        return $this->refusalOf($this->authorise(
            $this->validator->parse(BlossomVerb::Get, $authHeader),
            fn (Event $auth): ?BlossomFailure => $this->validator->requireBlobInScope($auth, $hash),
            fn (PublicKey $actor): ?BlossomFailure => $this->policy->allowGet($actor, $hash),
        ));
    }

    // Deliberate: the write pipeline binds the received bytes through the gate, not the validator, so the gate stays the one way to authorise; the bytes' hash exists only once they are staged, so this binding follows verify — see ADR-0029 and ADR-0026
    public function authoriseReceivedBlob(Event $auth, BlobHash $hash): ?BlossomFailure
    {
        return $this->validator->requireAuthorisedBlob($auth, $hash);
    }

    /**
     * @param Closure(Event): ?BlossomFailure     $requestChecks
     * @param Closure(PublicKey): ?BlossomFailure $allow
     */
    private function authorise(Event|BlossomFailure $parsed, Closure $requestChecks, Closure $allow): Event|BlossomFailure
    {
        if ($parsed instanceof BlossomFailure) {
            return $parsed;
        }

        // Deliberate: checks that read only the caller's own request and advertised limits run before verify(); the policy is asked only after it, so a refusal never reveals which keys are trusted — see ADR-0026
        return $requestChecks($parsed)
            ?? $this->validator->verify($parsed)
            ?? $allow($parsed->getPubkey())
            ?? $parsed;
    }

    private function refusalOf(Event|BlossomFailure $outcome): ?BlossomFailure
    {
        return $outcome instanceof BlossomFailure ? $outcome : null;
    }
}
