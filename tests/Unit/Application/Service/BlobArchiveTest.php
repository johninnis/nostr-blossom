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
use Innis\Nostr\Blossom\Tests\Support\TestDoubleException;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;

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

    public function testIndexFaultReleasesBytesNoTenantIndexes(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'orphan'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('delete')->with($descriptor->getSha256());

        try {
            new BlobArchive($store, $this->indexFailingToSave(new TestDoubleException('index unavailable'), null))->store($this->tenant(), '/tmp/upload', $descriptor);
        } catch (TestDoubleException) {
        }
    }

    public function testIndexFaultPropagatesOnceNoTenantIndexesTheBytes(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'orphan'));
        $fault = new TestDoubleException('index unavailable');

        $this->expectExceptionObject($fault);

        new BlobArchive($this->createStub(BlobStoreInterface::class), $this->indexFailingToSave($fault, null))->store($this->tenant(), '/tmp/upload', $descriptor);
    }

    public function testIndexFaultKeepsBytesAnotherTenantIndexes(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'shared'));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        try {
            new BlobArchive($store, $this->indexFailingToSave(new TestDoubleException('index unavailable'), $descriptor))->store($this->tenant(), '/tmp/upload', $descriptor);
        } catch (TestDoubleException) {
        }
    }

    public function testIndexFaultPropagatesWhileAnotherTenantIndexesTheBytes(): void
    {
        $descriptor = $this->descriptor(hash('sha256', 'shared'));

        $this->expectException(TestDoubleException::class);

        new BlobArchive($this->createStub(BlobStoreInterface::class), $this->indexFailingToSave(new TestDoubleException('index unavailable'), $descriptor))->store($this->tenant(), '/tmp/upload', $descriptor);
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

    public function testRetrieveReportsAnUnindexedHash(): void
    {
        $result = new BlobArchive($this->createStub(BlobStoreInterface::class), $this->createStub(BlobIndexInterface::class))->retrieve(BlossomFixtures::blobHash(hash('sha256', 'missing')));

        self::assertInstanceOf(BlobNotFoundFailure::class, $result);
    }

    public function testRetrieveNeverTouchesTheStoreForAnUnindexedHash(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('retrievePath');

        new BlobArchive($store, $this->createStub(BlobIndexInterface::class))->retrieve(BlossomFixtures::blobHash(hash('sha256', 'missing')));
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
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('delete');

        new BlobArchive($store, $this->indexReleasing(true, null))->removeForTenant($this->tenant(), BlossomFixtures::blobHash(hash('sha256', 'to delete')));
    }

    public function testReportsTheReleaseWhenLastTenantReleases(): void
    {
        self::assertTrue(new BlobArchive($this->createStub(BlobStoreInterface::class), $this->indexReleasing(true, null))->removeForTenant($this->tenant(), BlossomFixtures::blobHash(hash('sha256', 'to delete'))));
    }

    public function testKeepsBytesWhileAnotherTenantHolds(): void
    {
        $sha256 = hash('sha256', 'shared');

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        new BlobArchive($store, $this->indexReleasing(true, $this->descriptor($sha256)))->removeForTenant($this->tenant(), BlossomFixtures::blobHash($sha256));
    }

    public function testReportsTheReleaseWhileAnotherTenantHolds(): void
    {
        $sha256 = hash('sha256', 'shared');

        self::assertTrue(new BlobArchive($this->createStub(BlobStoreInterface::class), $this->indexReleasing(true, $this->descriptor($sha256)))->removeForTenant($this->tenant(), BlossomFixtures::blobHash($sha256)));
    }

    public function testReportsFailureWhenTenantDoesNotHoldBlob(): void
    {
        self::assertFalse(new BlobArchive($this->createStub(BlobStoreInterface::class), $this->indexReleasing(false, null))->removeForTenant($this->tenant(), BlossomFixtures::blobHash(hash('sha256', 'nonexistent'))));
    }

    public function testKeepsBytesWhenTenantDoesNotHoldBlob(): void
    {
        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        new BlobArchive($store, $this->indexReleasing(false, null))->removeForTenant($this->tenant(), BlossomFixtures::blobHash(hash('sha256', 'nonexistent')));
    }

    private function indexFailingToSave(TestDoubleException $fault, ?BlobDescriptor $indexedByAnotherTenant): BlobIndexInterface
    {
        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('save')->willThrowException($fault);
        $index->method('findByHash')->willReturn($indexedByAnotherTenant);

        return $index;
    }

    private function indexReleasing(bool $released, ?BlobDescriptor $heldByAnotherTenant): BlobIndexInterface
    {
        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn($released);
        $index->method('findByHash')->willReturn($heldByAnotherTenant);

        return $index;
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
