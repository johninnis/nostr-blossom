<?php

declare(strict_types=1);

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Port\PendingBlobSourceInterface;
use Innis\Nostr\Blossom\Application\Service\BlobDescriptorFactory;
use Innis\Nostr\Blossom\Application\Service\BlobIngestor;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidator;
use Innis\Nostr\Blossom\Application\Service\TenantBlossomPolicy;
use Innis\Nostr\Blossom\Application\UseCase\GetBlobUseCase;
use Innis\Nostr\Blossom\Application\UseCase\UploadBlobUseCase;
use Innis\Nostr\Blossom\Domain\Collection\AllowedMimeTypes;
use Innis\Nostr\Blossom\Domain\Collection\BlobDescriptorCollection;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\ListQuery;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerIdentity;
use Innis\Nostr\Blossom\Domain\ValueObject\TenantPubkeys;
use Innis\Nostr\Blossom\Domain\ValueObject\UploadConstraints;
use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\NostrAuthHeaderCodec;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

require __DIR__.'/../vendor/autoload.php';

$store = new class implements BlobStoreInterface {
    /** @var array<string, string> */
    private array $paths = [];

    #[Override]
    public function store(string $tempPath, BlobHash $hash): void
    {
        $this->paths[$hash->toHex()] = $tempPath;
    }

    #[Override]
    public function discard(string $tempPath): void
    {
        if (is_file($tempPath)) {
            unlink($tempPath);
        }
    }

    #[Override]
    public function retrievePath(BlobHash $hash): ?string
    {
        return $this->paths[$hash->toHex()] ?? null;
    }

    #[Override]
    public function delete(BlobHash $hash): bool
    {
        $key = $hash->toHex();
        if (!isset($this->paths[$key])) {
            return false;
        }

        $this->discard($this->paths[$key]);
        unset($this->paths[$key]);

        return true;
    }
};

$index = new class implements BlobIndexInterface {
    /** @var list<array{tenant: string, hash: string, descriptor: BlobDescriptor}> */
    private array $entries = [];

    #[Override]
    public function save(PublicKey $tenant, BlobDescriptor $descriptor): void
    {
        $this->entries[] = [
            'tenant' => $tenant->toHex(),
            'hash' => $descriptor->getSha256()->toHex(),
            'descriptor' => $descriptor,
        ];
    }

    #[Override]
    public function findByHash(BlobHash $hash): ?BlobDescriptor
    {
        foreach ($this->entries as $entry) {
            if ($entry['hash'] === $hash->toHex()) {
                return $entry['descriptor'];
            }
        }

        return null;
    }

    #[Override]
    public function ownsBlob(PublicKey $tenant, BlobHash $hash): bool
    {
        return array_any(
            $this->entries,
            static fn (array $entry): bool => $entry['tenant'] === $tenant->toHex() && $entry['hash'] === $hash->toHex(),
        );
    }

    #[Override]
    public function list(PublicKey $tenant, ListQuery $query): BlobDescriptorCollection
    {
        $owned = array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['tenant'] === $tenant->toHex(),
        ));

        $descriptors = array_map(
            static fn (array $entry): BlobDescriptor => $entry['descriptor'],
            array_slice($owned, 0, $query->getLimit()),
        );

        return new BlobDescriptorCollection($descriptors);
    }

    #[Override]
    public function deleteForTenant(PublicKey $tenant, BlobHash $hash): bool
    {
        $remaining = array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['tenant'] !== $tenant->toHex() || $entry['hash'] !== $hash->toHex(),
        ));

        if (count($remaining) === count($this->entries)) {
            return false;
        }

        $this->entries = $remaining;

        return true;
    }
};

$inspector = new class implements BlobInspectorInterface {
    #[Override]
    public function inspect(string $tempPath): IncomingBlob
    {
        $bytes = (string) file_get_contents($tempPath);
        $hash = BlobHash::tryFromHex(hash('sha256', $bytes)) ?? throw new RuntimeException('sha256 is always valid hex');

        return new IncomingBlob($hash, strlen($bytes));
    }

    #[Override]
    public function inspectWithMedia(string $tempPath): IncomingBlob
    {
        return $this->inspect($tempPath);
    }
};

$clock = new class implements ClockInterface {
    #[Override]
    public function now(): Timestamp
    {
        return Timestamp::now();
    }
};

$blobBytes = 'the bytes of a small png';
$tempPath = tempnam(sys_get_temp_dir(), 'blossom') ?: throw new RuntimeException('cannot allocate a temp file');
file_put_contents($tempPath, $blobBytes);

$source = new class($tempPath) implements PendingBlobSourceInterface {
    public function __construct(private readonly string $path)
    {
    }

    #[Override]
    public function stage(): PendingBlob
    {
        return new PendingBlob($this->path, MimeType::fromString('image/png'));
    }
};

$signatureService = Secp256k1Signer::create();
$tenant = KeyPair::generate($signatureService);

$config = new ServerConfig(
    new TenantPubkeys([$tenant->getPublicKey()]),
    new UploadConstraints(
        5 * 1024 * 1024,
        new AllowedMimeTypes([MimeType::tryFromString('image/png') ?? throw new RuntimeException('invalid allowed mime type')]),
    ),
    new ServerIdentity(HttpUrl::tryFromString('https://blossom.example') ?? throw new RuntimeException('invalid base url')),
);

$hash = BlobHash::tryFromHex(hash('sha256', $blobBytes)) ?? throw new RuntimeException('sha256 is always valid hex');

$authEvent = RumourFactory::createCustomKind(
    $tenant->getPublicKey(),
    EventKind::fromInt(EventKind::BLOSSOM_BLOB),
    EventContent::fromString('Upload authorisation'),
    new TagCollection([
        new Tag(TagType::hashtag(), ['upload']),
        new Tag(TagType::expiration(), [(string) (time() + 3600)]),
        new Tag(TagType::sha256(), [$hash->toHex()]),
    ]),
)->sign($tenant, $signatureService);

$authHeader = NostrAuthHeaderCodec::encode($authEvent);

$validator = new BlossomAuthValidator($signatureService, $clock, $config->getIdentity());
$policy = new TenantBlossomPolicy($config->getTenantPubkeys());
$ingestor = new BlobIngestor(
    new BlobValidator($inspector, $config->getUploadConstraints(), $validator),
    new BlobDescriptorFactory($config->getIdentity(), $clock),
    $store,
    $index,
);

$descriptor = new UploadBlobUseCase($validator, $policy, $ingestor)->execute($authHeader, $source, $hash);
if ($descriptor instanceof BlossomFailure) {
    fwrite(STDERR, sprintf("upload failed: %s (HTTP %d)\n", $descriptor->getMessage(), $descriptor->category()->httpStatus()));
    exit(1);
}

echo "Uploaded (BUD-02):\n";
echo json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

$retrieved = new GetBlobUseCase($store, $index, $validator, $policy)->execute($descriptor->getSha256());
if ($retrieved instanceof BlossomFailure) {
    fwrite(STDERR, sprintf("get failed: %s (HTTP %d)\n", $retrieved->getMessage(), $retrieved->category()->httpStatus()));
    exit(1);
}

echo "Served (BUD-01, public by hash):\n";
echo '  '.$retrieved->getDescriptor()->getSha256()->toHex().' -> '.$retrieved->getPath()."\n";
