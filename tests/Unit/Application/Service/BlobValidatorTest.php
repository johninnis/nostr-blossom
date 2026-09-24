<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\PendingBlob;
use Innis\Nostr\Blossom\Application\DTO\StorableBlob;
use Innis\Nostr\Blossom\Application\Port\BlobInspectorInterface;
use Innis\Nostr\Blossom\Application\Service\BlobValidator;
use Innis\Nostr\Blossom\Application\Service\BlossomAuthValidatorInterface;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobIntegrityFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\IncomingBlob;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerConfig;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlobValidatorTest extends TestCase
{
    private ServerConfig $config;

    protected function setUp(): void
    {
        $this->config = BlossomFixtures::serverConfig(
            tenantPubkeys: [BlossomFixtures::publicKey(str_repeat('b', 64))],
            maxUploadBytes: 1024,
            allowedMimeTypes: ['image/webp'],
            baseUrl: 'https://blossom.test',
        );
    }

    public function testValidatesAStorableUpload(): void
    {
        $hash = hash('sha256', 'stored');
        $blob = new IncomingBlob(BlossomFixtures::blobHash($hash), 512, null, BlossomFixtures::mimeType('image/webp'));

        $result = $this->validator($blob)->validateStorable(
            $this->authEvent('upload', $hash),
            new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')),
            null,
        );

        self::assertInstanceOf(StorableBlob::class, $result);
        self::assertSame($hash, $result->getHash()->toHex());
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'gif')), 512, null, BlossomFixtures::mimeType('image/gif'));

        $result = $this->validator($blob)->validateStorable(
            $this->authEvent('upload', hash('sha256', 'gif')),
            new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/gif')),
            null,
        );

        self::assertInstanceOf(UnsupportedMimeTypeFailure::class, $result);
    }

    public function testRejectsOversizedUpload(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'big')), 2048, null, BlossomFixtures::mimeType('image/webp'));

        $result = $this->validator($blob)->validateStorable(
            $this->authEvent('upload', hash('sha256', 'big')),
            new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')),
            null,
        );

        self::assertInstanceOf(BlobTooLargeFailure::class, $result);
    }

    public function testRejectsDeclaredHashMismatch(): void
    {
        $actual = hash('sha256', 'actual');
        $blob = new IncomingBlob(BlossomFixtures::blobHash($actual), 512, null, BlossomFixtures::mimeType('image/webp'));

        $result = $this->validator($blob)->validateStorable(
            $this->authEvent('upload', $actual),
            new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')),
            BlossomFixtures::blobHash(hash('sha256', 'declared')),
        );

        self::assertInstanceOf(BlobIntegrityFailure::class, $result);
    }

    public function testRejectsUploadNotNamedByAuthEvent(): void
    {
        $blob = new IncomingBlob(BlossomFixtures::blobHash(hash('sha256', 'stored')), 512, null, BlossomFixtures::mimeType('image/webp'));

        $result = $this->validator($blob, AuthorisationFailure::blobNotAuthorised())->validateStorable(
            $this->authEvent('upload', hash('sha256', 'a-different-blob')),
            new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')),
            null,
        );

        self::assertInstanceOf(AuthorisationFailure::class, $result);
    }

    public function testConvertsAnUnreadableBlobToAFailure(): void
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willThrowException(new RuntimeException('cannot read'));
        $validator = new BlobValidator($inspector, $this->config->getUploadConstraints(), $this->authValidator(null));

        $result = $validator->validateStorable(
            $this->authEvent('upload', hash('sha256', 'x')),
            new PendingBlob('/tmp/upload', BlossomFixtures::mimeType('image/webp')),
            null,
        );

        self::assertInstanceOf(BlobReadFailure::class, $result);
    }

    public function testAdmitOriginalDoesNotApplyTheStorageAllowList(): void
    {
        $originalHash = hash('sha256', 'original-jpeg');
        $blob = new IncomingBlob(BlossomFixtures::blobHash($originalHash), 512, null, BlossomFixtures::mimeType('image/jpeg'));

        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspect')->willReturn($blob);
        $validator = new BlobValidator($inspector, $this->config->getUploadConstraints(), $this->authValidator(null));

        $result = $validator->admitOriginal(
            $this->authEvent('media', $originalHash),
            new PendingBlob('/tmp/input', BlossomFixtures::mimeType('image/jpeg')),
        );

        self::assertInstanceOf(BlobHash::class, $result);
        self::assertSame($originalHash, $result->toHex());
    }

    private function validator(IncomingBlob $inspected, ?BlossomFailure $binding = null): BlobValidator
    {
        $inspector = $this->createStub(BlobInspectorInterface::class);
        $inspector->method('inspectWithMedia')->willReturn($inspected);

        return new BlobValidator($inspector, $this->config->getUploadConstraints(), $this->authValidator($binding));
    }

    private function authValidator(?BlossomFailure $binding): BlossomAuthValidatorInterface
    {
        $stub = $this->createStub(BlossomAuthValidatorInterface::class);
        $stub->method('requireAuthorisedBlob')->willReturn($binding);

        return $stub;
    }

    private function authEvent(string $verb, string $hash): Event
    {
        $json = (string) json_encode([
            'id' => str_repeat('a', 64),
            'pubkey' => str_repeat('b', 64),
            'created_at' => 1_000_000,
            'kind' => EventKind::BLOSSOM_BLOB,
            'tags' => [['t', $verb], [TagType::SHA256, $hash]],
            'content' => '',
            'sig' => str_repeat('c', 128),
        ]);

        return Event::tryFromJson($json) ?? throw new RuntimeException('invalid auth event fixture');
    }
}
