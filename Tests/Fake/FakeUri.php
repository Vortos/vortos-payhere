<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Fake;

use Psr\Http\Message\UriInterface;

/** Parses just enough of a URL for the fake client to route on path and query. */
final class FakeUri implements UriInterface
{
    /** @var array<string, string> */
    private array $parts;

    public function __construct(private readonly string $uri)
    {
        $parsed      = parse_url($uri);
        $this->parts = is_array($parsed) ? array_map('strval', array_filter($parsed, 'is_scalar')) : [];
    }

    public function getPath(): string
    {
        return $this->parts['path'] ?? '';
    }

    public function getQuery(): string
    {
        return $this->parts['query'] ?? '';
    }

    public function getHost(): string
    {
        return $this->parts['host'] ?? '';
    }

    public function getScheme(): string
    {
        return $this->parts['scheme'] ?? '';
    }

    public function __toString(): string
    {
        return $this->uri;
    }

    public function getAuthority(): string
    {
        return $this->getHost();
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getPort(): ?int
    {
        return isset($this->parts['port']) ? (int) $this->parts['port'] : null;
    }

    public function getFragment(): string
    {
        return $this->parts['fragment'] ?? '';
    }

    public function withScheme(string $scheme): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withHost(string $host): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withPort(?int $port): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withPath(string $path): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withQuery(string $query): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withFragment(string $fragment): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }
}
