<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Tests\Unit\Domain\Enum;

use Innis\Nostr\Blossom\Domain\Enum\BlossomFailureCategory;
use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\Failure\AuthorisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobIntegrityFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobNotFoundFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReadFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobReportFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlobTooLargeFailure;
use Innis\Nostr\Blossom\Domain\Failure\BlossomFailure;
use Innis\Nostr\Blossom\Domain\Failure\MediaOptimisationFailure;
use Innis\Nostr\Blossom\Domain\Failure\RemoteFetchFailure;
use Innis\Nostr\Blossom\Domain\Failure\UnsupportedMimeTypeFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlossomFailureCategoryTest extends TestCase
{
    /**
     * @return iterable<string, array{BlossomFailure, BlossomFailureCategory, int}>
     */
    public static function errorCategories(): iterable
    {
        yield 'authentication' => [AuthenticationFailure::missingHeader(), BlossomFailureCategory::Authentication, 401];
        yield 'authorisation' => [AuthorisationFailure::notTenant(), BlossomFailureCategory::Authorisation, 403];
        yield 'not found' => [BlobNotFoundFailure::forHash('abc'), BlossomFailureCategory::NotFound, 404];
        yield 'too large' => [BlobTooLargeFailure::forSize(2, 1), BlossomFailureCategory::PayloadTooLarge, 413];
        yield 'too large beyond the cap' => [BlobTooLargeFailure::beyondMaximum(1), BlossomFailureCategory::PayloadTooLarge, 413];
        yield 'unsupported type' => [UnsupportedMimeTypeFailure::forType(MimeType::fromString('text/plain')), BlossomFailureCategory::UnsupportedMediaType, 415];
        yield 'integrity' => [BlobIntegrityFailure::declaredHashMismatch('a', 'b'), BlossomFailureCategory::MalformedRequest, 400];
        yield 'read' => [BlobReadFailure::unreadable(), BlossomFailureCategory::Internal, 500];
        yield 'report' => [BlobReportFailure::malformedEvent(), BlossomFailureCategory::MalformedRequest, 400];
        yield 'optimisation' => [MediaOptimisationFailure::failed(MimeType::fromString('image/png')), BlossomFailureCategory::Internal, 500];
        yield 'remote fetch' => [RemoteFetchFailure::forUrl(HttpUrl::tryFromString('https://remote.test') ?? throw new LogicException('test url')), BlossomFailureCategory::UpstreamFailure, 502];
    }

    #[DataProvider('errorCategories')]
    public function testErrorExposesCategoryAndHttpStatus(BlossomFailure $error, BlossomFailureCategory $category, int $status): void
    {
        self::assertSame($category, $error->category());
        self::assertSame($status, $error->category()->httpStatus());
    }
}
