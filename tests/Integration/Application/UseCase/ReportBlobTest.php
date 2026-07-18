<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobReportStoreInterface;
use Innis\Nostr\Blossom\Application\UseCase\ReportBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\BlobReportFailure;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use PHPUnit\Framework\TestCase;

final class ReportBlobTest extends TestCase
{
    private KeyPair $keyPair;
    private SignatureServiceInterface $signatureService;

    protected function setUp(): void
    {
        $this->signatureService = Secp256k1Signer::create();
        $this->keyPair = KeyPair::generate($this->signatureService);
    }

    public function testPersistsReportForEachBlobHash(): void
    {
        $first = hash('sha256', 'first');
        $second = hash('sha256', 'second');

        $reports = $this->createMock(BlobReportStoreInterface::class);
        $reports->expects(self::exactly(2))->method('save');

        $report = new ReportBlobUseCase($reports, $this->signatureService);
        $result = $report->execute($this->reportEvent($first, $second));

        self::assertNull($result);
    }

    public function testPersistsOneReportPerDistinctBlobDespiteRepeatedTags(): void
    {
        $hash = hash('sha256', 'blob');

        $reports = $this->createMock(BlobReportStoreInterface::class);
        $reports->expects(self::once())->method('save');

        $report = new ReportBlobUseCase($reports, $this->signatureService);
        $result = $report->execute($this->reportEvent($hash, $hash, $hash));

        self::assertNull($result);
    }

    public function testRejectsMalformedEvent(): void
    {
        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
        $result = $report->execute('not json');

        self::assertInstanceOf(BlobReportFailure::class, $result);
    }

    public function testRejectsInvalidSignature(): void
    {
        $event = $this->signedReport($this->reportTags(hash('sha256', 'blob')));
        $data = $event->toArray();
        $data['sig'] = str_repeat('0', 128);

        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
        $result = $report->execute((string) json_encode($data));

        self::assertInstanceOf(BlobReportFailure::class, $result);
    }

    public function testRejectsWrongKind(): void
    {
        $event = RumourFactory::createTextNote($this->keyPair->getPublicKey(), 'hello')
            ->sign($this->keyPair, $this->signatureService);

        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
        $result = $report->execute((string) json_encode($event->toArray()));

        self::assertInstanceOf(BlobReportFailure::class, $result);
    }

    public function testRejectsReportWithoutBlobReference(): void
    {
        $reports = $this->createMock(BlobReportStoreInterface::class);
        $reports->expects(self::never())->method('save');

        $report = new ReportBlobUseCase($reports, $this->signatureService);
        $result = $report->execute($this->reportEvent());

        self::assertInstanceOf(BlobReportFailure::class, $result);
    }

    private function reportEvent(string ...$hashes): string
    {
        return (string) json_encode($this->signedReport($this->reportTags(...$hashes))->toArray());
    }

    private function reportTags(string ...$hashes): TagCollection
    {
        $tags = [new Tag(TagType::fromString('report'), ['other'])];
        foreach ($hashes as $hash) {
            $tags[] = new Tag(TagType::fromString('x'), [$hash]);
        }

        return new TagCollection($tags);
    }

    private function signedReport(TagCollection $tags): Event
    {
        return RumourFactory::createCustomKind(
            $this->keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::REPORTING),
            EventContent::fromString('spam'),
            $tags,
        )->sign($this->keyPair, $this->signatureService);
    }
}
