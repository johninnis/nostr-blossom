<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use Override;
use Stringable;

final readonly class MimeType implements Stringable
{
    public const string GENERIC = 'application/octet-stream';

    private const string ESSENCE_PATTERN = '~\A[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*\z~';

    private const array EXTENSIONS = [
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
        'image/gif' => '.gif',
        'image/webp' => '.webp',
        'image/svg+xml' => '.svg',
        'video/mp4' => '.mp4',
        'video/webm' => '.webm',
        'video/quicktime' => '.mov',
        'audio/mpeg' => '.mp3',
        'audio/ogg' => '.ogg',
        'audio/wav' => '.wav',
        'audio/webm' => '.weba',
        'application/pdf' => '.pdf',
        self::GENERIC => '',
    ];

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $essence = strstr($value, ';', true);

        // Deliberate: an unrecognised Content-Type header degrades to the generic type, never throws — see ADR-0005
        return self::tryFromString(false === $essence ? $value : $essence) ?? self::generic();
    }

    // Deliberate: the strict parser for untrusted configuration strings, which must reject rather than degrade — see ADR-0017
    public static function tryFromString(string $value): ?self
    {
        $essence = strtolower(trim($value));

        return 1 === preg_match(self::ESSENCE_PATTERN, $essence) ? new self($essence) : null;
    }

    public static function generic(): self
    {
        return new self(self::GENERIC);
    }

    public function isGeneric(): bool
    {
        return self::GENERIC === $this->value;
    }

    public function getExtension(): string
    {
        return self::EXTENSIONS[$this->value] ?? '';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
