<?php

declare(strict_types=1);

namespace Vortos\PayHere\Tests\Fake;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * An immutable-enough PSR-7 request: enough of the interface for the API
 * client's builder chain, and no more.
 */
final class FakeRequest implements RequestInterface
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?StreamInterface $body = null;

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
    ) {}

    public function getUri(): UriInterface
    {
        return new FakeUri($this->uri);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getBody(): StreamInterface
    {
        return $this->body ?? new FakeStream('');
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = is_array($value) ? implode(', ', $value) : (string) $value;

        return $clone;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone       = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function getHeaders(): array
    {
        return array_map(static fn (string $v): array => [$v], $this->headers);
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return isset($this->headers[strtolower($name)]) ? [$this->headers[strtolower($name)]] : [];
    }

    public function withAddedHeader(string $name, $value): static
    {
        return $this->withHeader($name, $value);
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): static
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return $this->uri;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withMethod(string $method): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        throw new \LogicException('Not needed by the PayHere client.');
    }
}
