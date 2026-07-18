<?php

declare(strict_types=1);

use Innis\Nostr\Blossom\Domain\Failure\AuthenticationFailure;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobDescriptor;
use Innis\Nostr\Blossom\Domain\ValueObject\BlobHash;
use Innis\Nostr\Blossom\Domain\ValueObject\HttpUrl;
use Innis\Nostr\Blossom\Domain\ValueObject\MimeType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

require __DIR__.'/../vendor/autoload.php';

$hash = BlobHash::tryFromHex('b1674191a88ec5cdd733e4240a81803105dc412d6c6708d53ab94fc248f4f553');
if (null === $hash) {
    fwrite(STDERR, "invalid hash\n");
    exit(1);
}

$url = HttpUrl::tryFromString('https://blossom.example/'.$hash->toHex());
if (null === $url) {
    fwrite(STDERR, "invalid url\n");
    exit(1);
}

$descriptor = new BlobDescriptor(
    $url,
    $hash,
    184_320,
    MimeType::fromString('image/png'),
    Timestamp::now(),
);

echo "Blob descriptor (BUD-02):\n";
echo json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

$failure = AuthenticationFailure::missingHeader();
echo 'Failure: '.$failure->getMessage().' -> HTTP '.$failure->category()->httpStatus()."\n";
