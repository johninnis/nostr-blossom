<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Application\Service;

use Innis\Nostr\Blossom\Application\Port\BlobIndexInterface;
use Innis\Nostr\Blossom\Application\Port\BlobStoreInterface;
use Innis\Nostr\Blossom\Application\Service\BlobRemover;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use PHPUnit\Framework\TestCase;

final class BlobRemoverTest extends TestCase
{
    private const string TENANT_HEX = '7e7e9c42a91bfef19fa929e5fda1b72e0ebc1a4c1141673e2794234d86addf4e';

    public function testRemovesBytesWhenLastTenantReleases(): void
    {
        $sha256 = hash('sha256', 'to delete');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(true);
        $index->method('findByHash')->willReturn(null);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::once())->method('delete');

        self::assertTrue(
            new BlobRemover($store, $index)->removeForTenant(BlossomFixtures::publicKey(self::TENANT_HEX), BlossomFixtures::blobHash($sha256)),
        );
    }

    public function testKeepsBytesWhileAnotherTenantHolds(): void
    {
        $sha256 = hash('sha256', 'shared');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(true);
        $index->method('findByHash')->willReturn($this->descriptor($sha256));

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        self::assertTrue(
            new BlobRemover($store, $index)->removeForTenant(BlossomFixtures::publicKey(self::TENANT_HEX), BlossomFixtures::blobHash($sha256)),
        );
    }

    public function testReportsFailureWhenTenantDoesNotHoldBlob(): void
    {
        $sha256 = hash('sha256', 'nonexistent');

        $index = $this->createStub(BlobIndexInterface::class);
        $index->method('deleteForTenant')->willReturn(false);

        $store = $this->createMock(BlobStoreInterface::class);
        $store->expects(self::never())->method('delete');

        self::assertFalse(
            new BlobRemover($store, $index)->removeForTenant(BlossomFixtures::publicKey(self::TENANT_HEX), BlossomFixtures::blobHash($sha256)),
        );
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
