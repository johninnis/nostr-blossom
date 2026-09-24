<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlossomPolicyInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Enum\BlossomVerb;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;

final readonly class UploadBlobUseCase
{
    public function __construct(
        private BlossomAuthValidatorInterface $authValidator,
        private BlossomPolicyInterface $policy,
        private BlobIngestor $ingestor,
    ) {
    }

    public function execute(string $authHeader, PendingBlobSourceInterface $source, ?BlobHash $declaredHash): BlobDescriptor|BlossomFailure
    {
        $auth = $this->authValidator->parse(BlossomVerb::Upload, $authHeader);
        if ($auth instanceof BlossomFailure) {
            return $auth;
        }

        // Deliberate: policy admission runs before verify(), so an unadmitted caller is rejected without paying secp256k1 — see ADR-0003
        $denied = $this->policy->allowUpload($auth->getPubkey())
            ?? $this->authValidator->verify($auth);
        if (null !== $denied) {
            return $denied;
        }

        $pending = $source->stage();
        if ($pending instanceof BlossomFailure) {
            return $pending;
        }

        return $this->ingestor->ingest($auth, $pending, $declaredHash);
    }
}
