<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\DTO\StorableBlob;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Domain\Failure\BlobIntegrityFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;
use Innis\Nostr\Core\Domain\Entity\Event;
use Throwable;

final readonly class BlobValidator
{
    public function __construct(
        private BlobInspectorInterface $inspector,
        private UploadConstraints $constraints,
        private BlossomAuthValidatorInterface $authValidator,
    ) {
    }

    public function validateStorable(Event $auth, PendingBlob $pending, ?BlobHash $declaredHash): StorableBlob|BlossomFailure
    {
        $blob = $this->readBlob($this->inspector->inspectWithMedia(...), $pending->getPath());
        if ($blob instanceof BlossomFailure) {
            return $blob;
        }

        $mimeType = $blob->resolvedMimeType($pending->getMimeType());

        return $this->guardStorable($blob, $mimeType)
            ?? $this->guardDeclaredHash($blob, $declaredHash)
            ?? $this->authValidator->requireAuthorisedBlob($auth, $blob->getHash())
            ?? new StorableBlob($blob, $pending->getPath(), $mimeType);
    }

    public function admitOriginal(Event $auth, PendingBlob $pending): BlobHash|BlossomFailure
    {
        $blob = $this->readBlob($this->inspector->inspect(...), $pending->getPath());
        if ($blob instanceof BlossomFailure) {
            return $blob;
        }

        return $this->constraints->guardUploadSize($blob->getSize())
            ?? $this->authValidator->requireAuthorisedBlob($auth, $blob->getHash())
            ?? $blob->getHash();
    }

    public function validateProduced(PendingBlob $produced, BlobHash $originalHash): StorableBlob|BlossomFailure
    {
        $blob = $this->readBlob($this->inspector->inspectWithMedia(...), $produced->getPath());
        if ($blob instanceof BlossomFailure) {
            return $blob;
        }

        $mimeType = $blob->resolvedMimeType($produced->getMimeType());

        return $this->guardStorable($blob, $mimeType)
            ?? new StorableBlob($blob, $produced->getPath(), $mimeType, $originalHash);
    }

    private function guardStorable(IncomingBlob $blob, MimeType $mimeType): ?BlossomFailure
    {
        return $this->constraints->guardUploadType($mimeType) ?? $this->constraints->guardUploadSize($blob->getSize());
    }

    private function guardDeclaredHash(IncomingBlob $blob, ?BlobHash $declaredHash): ?BlossomFailure
    {
        if (null !== $declaredHash && !$declaredHash->equals($blob->getHash())) {
            return BlobIntegrityFailure::declaredHashMismatch($declaredHash->toHex(), $blob->getHash()->toHex());
        }

        return null;
    }

    /**
     * @param callable(string): IncomingBlob $inspect
     */
    private function readBlob(callable $inspect, string $path): IncomingBlob|BlossomFailure
    {
        try {
            return $inspect($path);
        } catch (Throwable) {
            return BlobReadFailure::unreadable();
        }
    }
}
