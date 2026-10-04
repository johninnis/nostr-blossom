<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\DTO\RetrievedBlob;
use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Service\BlobArchive;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlobArchiveTest extends TestCase
{
    private const string TENANT_HEX = '7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e';

    /**
     * @var list<string>
     */
    private array $calls = [];

    public function testStoresTheBytesBeforeTheIndexNamesThem(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'stored'));

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('store')->willReturnCallback(function (): void { $this->calls[] = 'store'; });

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('save')->willReturnCallback(function (): void { $this->calls[] = 'save'; });

        new BlobArchive($store, $index)->store($this->tenant(), '/tmp/upload', $descriptor);

        self::assertSame(['store', 'save'], $this->calls);
    }

    public function testStoresTheStagedFileUnderTheDescriptorsHash(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'stored'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('store')->with('/tmp/upload', $descriptor->getSha256());

        new BlobArchive($store, $this->createStub(BlobIndexInterface::class))->store($this->tenant(), '/tmp/upload', $descriptor);
    }

    public function testIndexFaultReleasesBytesNoTenantIndexesAndPropagates(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'orphan'));
        $fault = new RuntimeException('index unavailable');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('save')->willThrowException($fault);
        $index->method('findByHash')->willReturn(null);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('delete')->with($descriptor->getSha256());

        $this->expectExceptionObject($fault);

        new BlobArchive($store, $index)->store($this->tenant(), '/tmp/upload', $descriptor);
    }

    public function testIndexFaultKeepsBytesAnotherTenantIndexes(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'shared'));

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('save')->willThrowException(new RuntimeException('index unavailable'));
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        $this->expectException(RuntimeException::class);

        new BlobArchive($store, $index)->store($this->tenant(), '/tmp/upload', $descriptor);
    }

    public function testDiscardsAStagedFile(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('discard')->with('/tmp/upload');

        new BlobArchive($store, $this->createStub(BlobIndexInterface::class))->discard('/tmp/upload');
    }

    public function testRetrievesTheDescriptorWithTheStoredPath(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'served'));

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $store = $this->createStub(BlobStoreInterface::class);
        $store->method('retrievePath')->willReturn('/var/blobs/served');

        $result = new BlobArchive($store, $index)->retrieve($descriptor->getSha256());

        self::assertEquals(new RetrievedBlob($descriptor, '/var/blobs/served'), $result);
    }

    public function testRetrieveReportsAnUnindexedHashWithoutTouchingTheStore(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('retrievePath');

        $result = new BlobArchive($store, $this->createStub(BlobIndexInterface::class))->retrieve(BlossomFixtures::blobHash(hash('sha256', 'missing')));

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testRetrieveReportsIndexedBytesMissingFromTheStore(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'lost'));

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('findByHash')->willReturn($descriptor);

        $result = new BlobArchive($this->createStub(BlobStoreInterface::class), $index)->retrieve($descriptor->getSha256());

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testRemovesBytesWhenLastTenantReleases(): void
    {
        $sha256 = hash('sha256', 'to delete');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(true);
        $index->method('findByHash')->willReturn(null);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('delete');

        self::assertTrue(new BlobArchive($store, $index)->removeForTenant($this->tenant(), BlossomFixtures::blobHash($sha256)));
    }

    public function testKeepsBytesWhileAnotherTenantHolds(): void
    {
        $sha256 = hash('sha256', 'shared');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(true);
        $index->method('findByHash')->willReturn($this->descriptor($sha256));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        self::assertTrue(new BlobArchive($store, $index)->removeForTenant($this->tenant(), BlossomFixtures::blobHash($sha256)));
    }

    public function testReportsFailureWhenTenantDoesNotHoldBlob(): void
    {
        $sha256 = hash('sha256', 'nonexistent');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(false);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        self::assertFalse(new BlobArchive($store, $index)->removeForTenant($this->tenant(), BlossomFixtures::blobHash($sha256)));
    }

    private function tenant(): PublicKey
    {
        return BlossomFixtures::publicKey(self::TENANT_HEX);
    }

    private function descriptor(string $sha256): BlobDescriptor
    {
        return new BlobDescriptor(
            url: BlossomFixtures::httpUrl('https://blossom.test/'.$sha256.'.png'),
            sha256: BlossomFixtures::blobHash($sha256),
            size: 1024,
            type: BlossomFixtures::mimeType('image/png'),
            uploaded: Timestamp::fromInt(1_000_000),
        );
    }
}
