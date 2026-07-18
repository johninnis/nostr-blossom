<?php

declare(strict_types=1);

namespace Innis\Nostr\Blossom\Domain\ValueObject;

use InvalidArgumentException;
use Override;
use Stringable;

final readonly class HttpUrl implements Stringable
{
    private function __construct(
        private string $url,
        private string $scheme,
        private string $host,
        private ?int $port,
    ) {
    }

    public static function tryFromString(string $url): ?self
    {
        if (1 === preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || '' === $host) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return new self(self::recompose($scheme, $host, $parts), $scheme, $host, $port);
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function append(string $segment): self
    {
        $pathEnd = strcspn($this->url, '?#');
        $candidate = rtrim(substr($this->url, 0, $pathEnd), '/').'/'.ltrim($segment, '/').substr($this->url, $pathEnd);

        $child = self::tryFromString($candidate);
        if (null === $child) {
            throw new InvalidArgumentException(sprintf('Cannot append "%s" to "%s"', $segment, $this->url));
        }

        return $child;
    }

    public function equals(self $other): bool
    {
        return $this->url === $other->url;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->url;
    }

    /**
     * @param array<string, int|string> $parts
     */
    private static function recompose(string $scheme, string $host, array $parts): string
    {
        $authority = $host;
        if (isset($parts['user'])) {
            $credentials = $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '');
            $authority = $credentials.'@'.$host;
        }
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return $scheme.'://'.$authority
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
