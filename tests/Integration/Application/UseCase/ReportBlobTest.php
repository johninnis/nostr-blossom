<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Integration\Application\UseCase;

use Innis\Nostr\Blossom\Application\Port\BlobReportStoreInterface;
use Innis\Nostr\Blossom\Application\UseCase\ReportBlobUseCase;
use Innis\Nostr\Blossom\Domain\Failure\BlobReportFailure;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
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
        $reports = $this->createMock(BlobReportStoreInterface::class);
        $reports->expects(self::exactly(2))->method('save');

        new ReportBlobUseCase($reports, $this->signatureService)->execute($this->reportEvent(hash('sha256', 'first'), hash('sha256', 'second')));
    }

    public function testAcceptsReportNamingSeveralBlobs(): void
    {
        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
        $result = $report->execute($this->reportEvent(hash('sha256', 'first'), hash('sha256', 'second')));

        self::assertNull($result);
    }

    public function testPersistsOneReportPerDistinctBlobDespiteRepeatedTags(): void
    {
        $hash = hash('sha256', 'blob');

        $reports = $this->createMock(BlobReportStoreInterface::class);
        $reports->expects(self::once())->method('save');

        new ReportBlobUseCase($reports, $this->signatureService)->execute($this->reportEvent($hash, $hash, $hash));
    }

    public function testAcceptsReportRepeatingOneBlob(): void
    {
        $hash = hash('sha256', 'blob');

        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
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
        $event = Rumour::draft($this->keyPair->getPublicKey(), EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('hello'))
            ->sign($this->keyPair, $this->signatureService);

        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
        $result = $report->execute((string) json_encode($event->toArray()));

        self::assertInstanceOf(BlobReportFailure::class, $result);
    }

    public function testRejectsReportWithoutBlobReference(): void
    {
        $report = new ReportBlobUseCase($this->createStub(BlobReportStoreInterface::class), $this->signatureService);
        $result = $report->execute($this->reportEvent());

        self::assertInstanceOf(BlobReportFailure::class, $result);
    }

    public function testPersistsNothingForReportWithoutBlobReference(): void
    {
        $reports = $this->createMock(BlobReportStoreInterface::class);
        $reports->expects(self::never())->method('save');

        new ReportBlobUseCase($reports, $this->signatureService)->execute($this->reportEvent());
    }

    private function reportEvent(string ...$hashes): string
    {
        return (string) json_encode($this->signedReport($this->reportTags(...$hashes))->toArray());
    }

    private function reportTags(string ...$hashes): TagCollection
    {
        $tags = [Tag::fromArray([(string) TagType::fromString('report'), 'other'])];
        foreach ($hashes as $hash) {
            $tags[] = Tag::fromArray([TagType::SHA256, $hash]);
        }

        return new TagCollection($tags);
    }

    private function signedReport(TagCollection $tags): Event
    {
        return Rumour::draft(
            $this->keyPair->getPublicKey(),
            EventKind::fromInt(EventKind::REPORTING),
            EventContent::fromString('spam'),
            $tags,
        )->sign($this->keyPair, $this->signatureService);
    }
}
