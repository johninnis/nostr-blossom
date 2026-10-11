<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\TenantPubkeys;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TenantPubkeysTest extends TestCase
{
    public function testContainsListedPubkey(): void
    {
        self::assertTrue($this->twoTenants()->contains(BlossomFixtures::publicKey(str_repeat('a', 64))));
    }

    public function testContainsEachListedPubkey(): void
    {
        self::assertTrue($this->twoTenants()->contains(BlossomFixtures::publicKey(str_repeat('b', 64))));
    }

    public function testDoesNotContainAnUnlistedPubkey(): void
    {
        self::assertFalse($this->twoTenants()->contains(BlossomFixtures::publicKey(str_repeat('c', 64))));
    }

    public function testToArrayDeduplicatesByPubkey(): void
    {
        $pubkey = BlossomFixtures::publicKey(str_repeat('a', 64));

        $tenants = new TenantPubkeys([$pubkey, $pubkey]);

        self::assertCount(1, $tenants->toArray());
    }

    public function testRejectsEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantPubkeys([]);
    }

    public function testRejectsNonPublicKeyMember(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantPubkeys([BlossomFixtures::publicKey(str_repeat('a', 64)), 'not-a-pubkey']);
    }

    private function twoTenants(): TenantPubkeys
    {
        return new TenantPubkeys([BlossomFixtures::publicKey(str_repeat('a', 64)), BlossomFixtures::publicKey(str_repeat('b', 64))]);
    }
}
