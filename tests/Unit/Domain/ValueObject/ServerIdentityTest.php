<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\ServerIdentity;
use Innis\Nostr\Blossom\Tests\Support\BlossomFixtures;
use PHPUnit\Framework\TestCase;

final class ServerIdentityTest extends TestCase
{
    public function testRecognisesItsOwnDomain(): void
    {
        self::assertTrue($this->identity()->isThisServer('example.com'));
    }

    public function testComparesDomainCaseInsensitively(): void
    {
        self::assertTrue($this->identity()->isThisServer('EXAMPLE.COM'));
    }

    public function testRejectsAnotherDomain(): void
    {
        self::assertFalse($this->identity()->isThisServer('other.example.com'));
    }

    public function testBuildBlobUrl(): void
    {
        $identity = BlossomFixtures::serverIdentity('https://blossom.example.com');
        $hash = $this->hash('test');

        $url = $identity->buildBlobUrl($hash, BlossomFixtures::mimeType('image/png'));

        self::assertSame('https://blossom.example.com/'.$hash->toHex().'.png', (string) $url);
    }

    public function testBuildBlobUrlUsesNormalisedBaseUrl(): void
    {
        $identity = BlossomFixtures::serverIdentity('https://blossom.example.com/');
        $hash = $this->hash('test');

        $url = $identity->buildBlobUrl($hash, BlossomFixtures::mimeType('image/jpeg'));

        self::assertSame('https://blossom.example.com/'.$hash->toHex().'.jpg', (string) $url);
    }

    private function identity(): ServerIdentity
    {
        return BlossomFixtures::serverIdentity('https://example.com');
    }

    private function hash(string $content): BlobHash
    {
        return BlossomFixtures::blobHash(hash('sha256', $content));
    }
}
